<?php
session_start();

if (!isset($_SESSION['user_id'])) {
    header("Location: login.html");
    exit();
}

$user_id      = $_SESSION['user_id'];
$portfolio_id = intval($_POST['portfolio_id'] ?? 0);
$no_of_stocks = intval($_POST['no_of_stocks'] ?? 0);

if ($portfolio_id <= 0 || $no_of_stocks <= 0) {
    die("Invalid rebalance request.");
}

$apiUrl = "http://127.0.0.1:8001/rebalance_portfolio";

$payload = json_encode([
    "portfolio_id" => $portfolio_id,
    "user_id"      => $user_id,
    "no_of_stocks" => $no_of_stocks
]);

$options = [
    "http" => [
        "method"        => "POST",
        "header"        => "Content-Type: application/json\r\n",
        "content"       => $payload,
        "timeout"       => 180,
        "ignore_errors" => true
    ]
];

$context  = stream_context_create($options);
$response = @file_get_contents($apiUrl, false, $context);

$statusLine = $http_response_header[0] ?? '';
$statusCode = 0;
if (preg_match('#HTTP/\d\.\d\s+(\d{3})#', $statusLine, $m)) {
    $statusCode = (int)$m[1];
}

$data = json_decode($response, true);

// handle 30‑day rule and external fetch issues nicely
if (in_array($statusCode, [400, 502, 503]) && isset($data['detail'])) {
    $detail = $data['detail'];
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <title>Unable to Update Portfolio</title>
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet" />
    </head>
    <body class="bg-light">
    <div class="container d-flex flex-column justify-content-center align-items-center"
         style="min-height: 100vh;">
        <div class="card shadow-sm p-4" style="max-width: 480px; width: 100%;">
            <h4 class="mb-3 text-center text-primary">Unable to Update Portfolio</h4>
            <p class="mb-3 text-center">
                <?= htmlspecialchars($detail); ?>
            </p>
            <div class="d-flex justify-content-center gap-2">
                <a href="view_portfolio_details.php?portfolio_id=<?= (int)$portfolio_id; ?>"
                   class="btn btn-primary">Back to Portfolio</a>
                <a href="view_portfolio.php" class="btn btn-outline-secondary">All Portfolios</a>
            </div>
        </div>
    </div>
    </body>
    </html>
    <?php
    exit();
}

// any other error
if ($statusCode >= 400 || empty($data['success'])) {
    $err = $data['detail'] ?? 'Unknown error from rebalance API';
    die("Rebalance failed: " . htmlspecialchars($err));
}

// success → go back to details
header("Location: view_portfolio_details.php?portfolio_id=" . $portfolio_id);
exit();
