<?php
session_start();
require_once './db_connect.php';

// Ensure user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: login.html");
    exit();
}

$user_id  = $_SESSION['user_id'];
$username = $_SESSION['username'];

// 1. Total portfolios for this user (distinct portfolios via user_portfolios)
$sqlPortfolios = "
    SELECT COUNT(*) AS total_portfolios
    FROM user_portfolios
    WHERE user_id = ?
";
$stmt = $conn->prepare($sqlPortfolios);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result()->fetch_assoc();
$total_portfolios = $result['total_portfolios'] ?? 0;
$stmt->close();

// 2 & 3. Total invested and current value across all holdings
//    join user_portfolios -> portfolios -> portfolio_holdings
$sqlSummary = "
    SELECT
        COALESCE(SUM(ph.invested_amount), 0) AS total_investment,
        COALESCE(SUM(ph.current_value), 0)    AS total_current
    FROM user_portfolios up
    JOIN portfolios p       ON up.portfolio_id = p.portfolio_id
    JOIN portfolio_holdings ph ON p.portfolio_id = ph.portfolio_id
    WHERE up.user_id = ?
";
$stmt = $conn->prepare($sqlSummary);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$sumRow = $stmt->get_result()->fetch_assoc();
$stmt->close();

$total_investment = (float)($sumRow['total_investment'] ?? 0);
$total_current    = (float)($sumRow['total_current'] ?? 0);

// Net P/L and returns
$net_profit_loss     = $total_current - $total_investment;
$net_returns_percent = ($total_investment != 0)
    ? ($net_profit_loss / $total_investment) * 100
    : 0.0;

// CSS classes for coloring
$current_value_class    = ($total_current >= $total_investment) ? 'text-success-thick' : 'text-danger-thick';
$net_profit_loss_class  = ($net_profit_loss >= 0) ? 'text-success-thick' : 'text-danger-thick';
$net_returns_class      = ($net_returns_percent >= 0) ? 'text-success-thick' : 'text-danger-thick';

$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Dashboard - Portfolio Management System</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet" />
  <link rel="stylesheet" href="../css/dashboard.css" />
</head>
<body>
  <!-- Navbar -->
  <nav class="navbar navbar-expand-lg sticky-top navbar-light bg-light shadow-sm">
    <div class="container-fluid">
      <a class="navbar-brand fw-bold" href="dashboard.php">Portfolio System</a>
      <button
        class="navbar-toggler"
        type="button"
        data-bs-toggle="collapse"
        data-bs-target="#navbarSupportedContent"
        aria-controls="navbarSupportedContent"
        aria-expanded="false"
        aria-label="Toggle navigation"
      >
        <span class="navbar-toggler-icon"></span>
      </button>
      <div class="collapse navbar-collapse" id="navbarSupportedContent">
        <ul class="navbar-nav ms-auto mb-2 mb-lg-0 gap-3">
          <li class="nav-item">
            <a class="nav-link active" href="dashboard.php">Dashboard</a>
          </li>
          <li class="nav-item">
            <a class="nav-link" href="add_portfolio.php">Add Portfolio</a>
          </li>
          <li class="nav-item">
            <a class="nav-link" href="view_portfolio.php">View Portfolio</a>
          </li>
          <li class="nav-item">
            <a class="nav-link" href="logout.php" onclick="return confirmLogout()">Logout</a>
          </li>
        </ul>
      </div>
    </div>
  </nav>

  <!-- Main container -->
  <main class="container my-5">
    <div class="d-flex justify-content-between align-items-center mb-4 flex-column flex-md-row">
      <h1 class="fw-bold pastel-text">Dashboard</h1>
      <div class="welcome-text">
        Welcome, <strong><?= htmlspecialchars($username) ?></strong>!
      </div>
    </div>

    <!-- Summary Cards -->
    <div class="summary-section">
      <div class="summary-row">
        <div class="summary-card">
          <h5>Total Portfolio</h5>
          <p><?= $total_portfolios ?></p>
        </div>
        <div class="summary-card">
          <h5>Total Investment</h5>
          <p>₹<?= number_format($total_investment, 2) ?></p>
        </div>
        <div class="summary-card">
          <h5 class="<?= $current_value_class ?>">Current Value</h5>
          <p class="<?= $current_value_class ?>">₹<?= number_format($total_current, 2) ?></p>
        </div>
      </div>
      <div class="summary-row">
        <div class="summary-card">
          <h5 class="<?= $net_profit_loss_class ?>">Net Profit/Loss</h5>
          <p class="<?= $net_profit_loss_class ?>">₹<?= number_format($net_profit_loss, 2) ?></p>
        </div>
        <div class="summary-card">
          <h5 class="<?= $net_returns_class ?>">Net Returns (%)</h5>
          <p class="<?= $net_returns_class ?>"><?= number_format($net_returns_percent, 2) ?>%</p>
        </div>
      </div>
    </div>

    <!-- Action Buttons -->
    <div class="d-flex flex-wrap gap-3 justify-content-center mt-5">
      <button class="btn btn-custom" onclick="location.href='add_portfolio.php'">Add Portfolio</button>
      <button class="btn btn-custom" onclick="location.href='view_portfolio.php'">View Portfolio</button>
      <button class="btn btn-custom" onclick="return confirmLogout()">Logout</button>
    </div>
  </main>

  <script>
  function confirmLogout() {
      if (confirm('Are you sure you want to logout?')) {
          window.location.href = 'logout.php';
          return true;
      }
      return false;
  }
  </script>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
