<?php
session_start();
require_once './db_connect.php';

// Ensure user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: login.html");
    exit();
}

$user_id   = $_SESSION['user_id'];
$username  = $_SESSION['username'];
$message   = "";

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $investment_amount = floatval($_POST['investment_amount'] ?? 0);
    $selected_index    = $_POST['index'] ?? "";

    // Validate investment amount (min 100000)
    if ($investment_amount < 100000) {
        $message = "Investment amount should be at least ₹1,00,000";
    } elseif (empty($selected_index)) {
        $message = "Please select an index.";
    } else {
        // Store investment amount in session so it can be used later
        $_SESSION['investment_amount'] = $investment_amount;

        // Redirect to view_scripts.php to show scripts of the selected index
        header("Location: view_scripts.php?symbol=" . urlencode($selected_index));
        exit();
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Add Portfolio</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet" />
  <link rel="stylesheet" href="../css/add_portfolio.css" />
</head>
<body>
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
          <li class="nav-item"><a class="nav-link" href="dashboard.php">Dashboard</a></li>
          <li class="nav-item"><a class="nav-link active" href="add_portfolio.php">Add Portfolio</a></li>
          <li class="nav-item"><a class="nav-link" href="view_portfolio.php">View Portfolio</a></li>
          <li class="nav-item"><a class="nav-link" href="logout.php" onclick="return confirmLogout()">Logout</a></li>
        </ul>
      </div>
    </div>
  </nav>

  <div class="container my-5">
    <h2>Add Portfolio</h2>

    <?php if ($message): ?>
      <div class="alert alert-danger"><?= htmlspecialchars($message) ?></div>
    <?php endif; ?>

    <form method="post" action="add_portfolio.php" class="mb-4">
      <div class="inputGroup">
        <label for="investment_amount" class="form-label">Investment Amount (₹, minimum 1,00,000)</label>
        <input
          type="number"
          min="100000"
          step="0.01"
          name="investment_amount"
          id="investment_amount"
          placeholder="Enter investment amount"
          required
          value="<?= htmlspecialchars($_POST['investment_amount'] ?? '') ?>"
        />
      </div>

      <div class="inputGroup">
        <label for="index" class="form-label">Select Index</label>
        <select name="index" id="index" required>
          <option value="">Choose...</option>
          <?php
          $result = $conn->query("SELECT symbol FROM indices ORDER BY symbol");
          while ($row = $result->fetch_assoc()) {
            $symbol   = htmlspecialchars($row['symbol']);
            $selected = (isset($_POST['index']) && $_POST['index'] === $row['symbol']) ? 'selected' : '';
            echo "<option value=\"$symbol\" $selected>$symbol</option>";
          }
          ?>
        </select>
      </div>

      <button type="submit" class="btnPrimary">OK</button>
    </form>
  </div>

  <script>
  function confirmLogout() {
      return confirm('Are you sure you want to logout?');
  }
  </script>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>

<?php $conn->close(); ?>
