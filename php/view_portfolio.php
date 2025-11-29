<?php
session_start();
require_once 'db_connect.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.html");
    exit();
}

$user_id = $_SESSION['user_id'];

/* ----- Handle delete entire portfolio ----- */
if ($_SERVER['REQUEST_METHOD'] === 'POST'
    && isset($_POST['action'], $_POST['portfolio_id'])
    && $_POST['action'] === 'delete_portfolio') {

    $portfolio_id = intval($_POST['portfolio_id']);

    if ($portfolio_id > 0) {
        $stmt = $conn->prepare("SELECT user_portfolio_id FROM user_portfolios WHERE user_id = ? AND portfolio_id = ?");
        $stmt->bind_param("ii", $user_id, $portfolio_id);
        $stmt->execute();
        $res = $stmt->get_result();
        $hasAccess = ($res->num_rows > 0);
        $stmt->close();

        if ($hasAccess) {
            // delete holdings
            $stmt = $conn->prepare("DELETE FROM portfolio_holdings WHERE portfolio_id = ?");
            $stmt->bind_param("i", $portfolio_id);
            $stmt->execute();
            $stmt->close();

            // delete user-portfolio link for this user
            $stmt = $conn->prepare("DELETE FROM user_portfolios WHERE user_id = ? AND portfolio_id = ?");
            $stmt->bind_param("ii", $user_id, $portfolio_id);
            $stmt->execute();
            $stmt->close();

            // if no other users subscribed, delete portfolio
            $stmt = $conn->prepare("SELECT COUNT(*) AS cnt FROM user_portfolios WHERE portfolio_id = ?");
            $stmt->bind_param("i", $portfolio_id);
            $stmt->execute();
            $cntRow = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (($cntRow['cnt'] ?? 0) == 0) {
                $stmt = $conn->prepare("DELETE FROM portfolios WHERE portfolio_id = ?");
                $stmt->bind_param("i", $portfolio_id);
                $stmt->execute();
                $stmt->close();
            }
        }
    }

    header("Location: view_portfolio.php");
    exit();
}

/* ----- Fetch aggregated portfolios for this user ----- */
$allowedSorts = ['portfolio_name', 'total_invested', 'total_current', 'pl_amount', 'pl_percent'];
$sort  = 'portfolio_name';
$order = 'asc';

if (isset($_GET['sort']) && in_array($_GET['sort'], $allowedSorts, true)) {
    $sort = $_GET['sort'];
}
if (isset($_GET['order']) && in_array(strtolower($_GET['order']), ['asc', 'desc'], true)) {
    $order = strtolower($_GET['order']);
}

$sql = "
    SELECT
        p.portfolio_id,
        p.portfolio_name,
        p.description,
        COALESCE(SUM(ph.invested_amount), 0) AS total_invested,
        COALESCE(SUM(ph.current_value), 0)    AS total_current,
        COALESCE(SUM(ph.current_value) - SUM(ph.invested_amount), 0) AS pl_amount,
        CASE
            WHEN SUM(ph.invested_amount) > 0
            THEN (SUM(ph.current_value) - SUM(ph.invested_amount)) / SUM(ph.invested_amount) * 100
            ELSE 0
        END AS pl_percent
    FROM user_portfolios up
    JOIN portfolios p
        ON up.portfolio_id = p.portfolio_id
    LEFT JOIN portfolio_holdings ph
        ON p.portfolio_id = ph.portfolio_id
    WHERE up.user_id = ?
    GROUP BY p.portfolio_id, p.portfolio_name, p.description
    ORDER BY `$sort` $order
";

$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();

function sortArrowsPortfolio($column, $currentSort, $currentOrder) {
    $baseUrl   = strtok($_SERVER['REQUEST_URI'], '?');
    $paramsAsc  = http_build_query(['sort' => $column, 'order' => 'asc']);
    $paramsDesc = http_build_query(['sort' => $column, 'order' => 'desc']);

    $highlight = "#174EA6";
    $normal    = "inherit";

    $styleUp = ($currentSort === $column && $currentOrder === 'asc')
        ? "color: $highlight; font-weight: bold;"
        : "color: $normal;";
    $styleDown = ($currentSort === $column && $currentOrder === 'desc')
        ? "color: $highlight; font-weight: bold;"
        : "color: $normal;";

    return
        '<span class="sort-arrows">' .
        '<a href="' . $baseUrl . '?' . $paramsAsc  . '" style="text-decoration:none; ' . $styleUp   . '" title="Sort ascending">&#9650;</a>' .
        '<a href="' . $baseUrl . '?' . $paramsDesc . '" style="text-decoration:none; margin-left:3px; ' . $styleDown . '" title="Sort descending">&#9660;</a>' .
        '</span>';
}

date_default_timezone_set('Asia/Kolkata');
$yesterday = date('d M Y', strtotime('-1 day'));
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <title>Your Portfolios</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet" />
    <link rel="stylesheet" href="../css/view_portfolio.css" />
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
                <li class="nav-item"><a class="nav-link" href="logout.php" onclick="return confirmLogout()">Logout</a></li>
            </ul>
        </div>
    </div>
</nav>

<main class="main-content">
    <div class="dashboard-header">
        <h1>Your Portfolios</h1>
        <div class="welcome-msg">
            Welcome, <strong><?= htmlspecialchars($_SESSION['username']); ?></strong>!
        </div>
    </div>

    <?php if ($result->num_rows > 0): ?>
        <table class="portfolio-table">
            <thead>
                <tr>
                    <th>Sr No.</th>
                    <th>Portfolio<?= sortArrowsPortfolio('portfolio_name', $sort, $order); ?></th>
                    <th>Description</th>
                    <th>Total Invested (₹)<?= sortArrowsPortfolio('total_invested', $sort, $order); ?></th>
                    <th>
                        Current Value (₹)
                        <div>as of <?= $yesterday; ?></div>
                        <?= sortArrowsPortfolio('total_current', $sort, $order); ?>
                    </th>
                    <th>Net P/L (₹)<?= sortArrowsPortfolio('pl_amount', $sort, $order); ?></th>
                    <th>Net P/L (%)<?= sortArrowsPortfolio('pl_percent', $sort, $order); ?></th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
            <?php $sr = 1; ?>
            <?php while ($row = $result->fetch_assoc()):
                $current_value_class = ($row['total_current'] >= $row['total_invested'])
                    ? 'text-success-thick' : 'text-danger-thick';
                $pl_class  = ($row['pl_amount']  >= 0) ? 'text-success-thick' : 'text-danger-thick';
                $plp_class = ($row['pl_percent'] >= 0) ? 'text-success-thick' : 'text-danger-thick';
            ?>
                <tr>
                    <td><?= $sr++; ?></td>
                    <td><?= htmlspecialchars($row['portfolio_name']); ?></td>
                    <td><?= htmlspecialchars($row['description']); ?></td>
                    <td><?= number_format($row['total_invested'], 2); ?></td>
                    <td class="<?= $current_value_class; ?>"><?= number_format($row['total_current'], 2); ?></td>
                    <td class="<?= $pl_class; ?>"><?= number_format($row['pl_amount'], 2); ?></td>
                    <td class="<?= $plp_class; ?>"><?= number_format($row['pl_percent'], 2); ?></td>
                    <?php
                    // compute whether update is allowed (e.g. based on last_rebalanced_at you fetched)
                    $can_update = true;  // replace with real condition later
                    ?>

                    <td>
                        <div class="d-flex flex-column gap-2 action-btn-group">
                            <a href="view_portfolio_details.php?portfolio_id=<?= (int)$row['portfolio_id']; ?>"
                            class="btn btn-sm btn-primary btn-action">View Details</a>

                            <form method="post" action="rebalance_portfolio.php"
                                onsubmit="return <?= $can_update ? "confirm('Rebalance portfolio " . htmlspecialchars($row['portfolio_name']) . " based on latest 30-day returns?')" : "false"; ?>;">
                                <input type="hidden" name="portfolio_id" value="<?= (int)$row['portfolio_id']; ?>">
                                <input type="hidden" name="no_of_stocks" value="20">
                                <button type="submit"
                                        class="btn btn-sm btn-action <?= $can_update ? 'btn-update-available' : 'btn-update-disabled'; ?>"
                                        <?= $can_update ? '' : 'disabled'; ?>>
                                    Update Portfolio
                                </button>
                            </form>

                            <form method="post" action="view_portfolio.php"
                                onsubmit="return confirm('Delete portfolio <?= htmlspecialchars($row['portfolio_name']); ?> and all its holdings?');">
                                <input type="hidden" name="action" value="delete_portfolio">
                                <input type="hidden" name="portfolio_id" value="<?= (int)$row['portfolio_id']; ?>">
                                <button type="submit" class="btn btn-sm btn-danger btn-action">Delete Portfolio</button>
                            </form>
                        </div>
                    </td>
                </tr>
            <?php endwhile; ?>
            </tbody>
        </table>
    <?php else: ?>
        <p style="margin-top: 20px;">You have no portfolios to show.</p>
    <?php endif; ?>
</main>

<script>
function confirmLogout() {
    return confirm('Are you sure you want to logout?');
}
</script>

</body>
</html>

<?php
$stmt->close();
$conn->close();
?>
