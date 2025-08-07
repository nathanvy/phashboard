<?php
// database config
$host = '127.0.0.1';
$port = '5432';
$db   = 'foo';
$user = 'bar';
$pass = 'hunter2';
$dsn  = "pgsql:host={$host};port={$port};dbname={$db}";

// parse requested date from url
$requestUrl = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$segments = explode('/', trim($requestUrl, '/'));
if (isset($segments[0]) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $segments[0])) {
    $date = $segments[0];
} else {
    $date = date('Y-m-d');
}

$d = DateTime::createFromFormat('Y-m-d', $date);
if (!$d || $d->format('Y-m-d') !== $date) {
    http_response_code(400);
    header('Content-Type: text/plain');
    echo 'Invalid date format';
    exit;
}

try {
    $pdo = new PDO($dsn, $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
} catch (PDOException $e) {
    die("DB Connection failed: " . $e->getMessage());
}

// Simply fetch all trades in table
$sql  = 'SELECT * FROM trades WHERE dtg::date = :date ORDER BY dtg';
$stmt = $pdo->prepare($sql);
$stmt->execute(['date' => $date]);
$trades = $stmt->fetchAll(PDO::FETCH_ASSOC);

//pair open/close trades and compute base fields + realized P&L in cents
function matchTrades(array $trades): array
{
    $opens     = [];
    $completed = [];

    foreach ($trades as $t) {
        $right  = stripos($t['symbol'], 'Call') !== false ? 'CALL' : 'PUT';
        $key    = implode('|', [
            $t['symbol'],
            $t['strike'],
            $t['expiry'],
            $t['qty'],
            $right
        ]);
        $effect = strtolower($t['effect']);

        if (strpos($effect, 'open') !== false) {
            $opens[$key][] = $t;
        } elseif (strpos($effect, 'close') !== false) {
            if (!empty($opens[$key])) {
                $open = array_shift($opens[$key]);
                $completed[] = [
                    'symbol' => $t['symbol'],
                    'strike' => $t['strike'],
                    'expiry' => $t['expiry'],
                    'qty' => $open['qty'],

                    'open_dtg' => $open['dtg'],
                    'close_dtg' => $t['dtg'],

                    'open_price' => $open['price'] * 100,
                    'close_price' => $t['price'] * 100,

                    'open_spot' => $open['spot'],
                    'close_spot' => $t['spot'],

                    'open_iv' => $open['iv'] * 100,
                    'close_iv' => $t['iv'] * 100,

                    'open_delta' => $open['delta'],
                    'open_theta' => $open['theta'],
                    'open_vega' => $open['vega'],
                    'open_gamma' => $open['gamma'],

                    'close_delta' => $t['delta'],
                    'close_theta' => $t['theta'],
                    'close_vega' => $t['vega'],
                    'close_gamma' => $t['gamma'],

                    'pl' => ($t['price'] - $open['price']) * 100 * $open['qty'],
                ];
            }
        }
    }

    return $completed;
}

/**
 * Decompose realized PnL into Greek contributions and residual
 */
function decomposePnL(array $trade): array
{
    // Total PnL (in cents * qty)
    $p0 = (float) $trade['open_price'];
    $p1 = (float) $trade['close_price'];
    $d_pnl = ($p1 - $p0) * $trade['qty'];

    // Spot and IV moves
    $d_spot = $trade['close_spot'] - $trade['open_spot'];
    $d_sigma = $trade['close_iv'] - $trade['open_iv'];

    // Time in days
    $openDT = new DateTime($trade['open_dtg']);
    $closeDT = new DateTime($trade['close_dtg']);
    $dt = ($closeDT->getTimestamp() - $openDT->getTimestamp()) / 86400.0;

    // Initial Greeks at open
    $delta0 = (float) $trade['open_delta'];
    $theta0 = (float) $trade['open_theta'];
    $vega0 = (float) $trade['open_vega'];
    $gamma0 = (float) $trade['open_gamma'];

    // Greek P&L contributions
    $delta_pl = $delta0 * $d_spot * 100 * $trade['qty'];
    $theta_pl = $theta0 * $dt * 100 * $trade['qty'];
    $vega_pl = $vega0 * $d_sigma * 100 * $trade['qty'];
    $gamma_pl = 0.5 * $gamma0 * ($d_spot ** 2) * 100 * $trade['qty'];

    // Residual
    $residual = $d_pnl - ($delta_pl + $theta_pl + $vega_pl + $gamma_pl);

    return [
        'dPnL' => $d_pnl,
        'deltaPL' => $delta_pl,
        'thetaPL' => $theta_pl,
        'vegaPL' => $vega_pl,
        'gammaPL' => $gamma_pl,
        'residual' => $residual,
    ];
}

// Match and decompose
$completedTrades = matchTrades($trades);
foreach ($completedTrades as &$ct) {
    $breakdown = decomposePnL($ct);
    $ct = array_merge($ct, $breakdown);
}
unset($ct);

function computeEquitySeries(array $completedTrades): array
{
    if (empty($completedTrades)) {
        return [];
    }

    usort($completedTrades, function ($a, $b) {
        return strtotime($a['close_dtg']) <=> strtotime($b['close_dtg']);
    });

    $firstDt = new DateTime($completedTrades[0]['close_dtg']);
    $curveDate = $firstDt->format('Y-m-d');

    $sessionStart = new DateTime("$curveDate 09:30:00");
    $sessionEnd = new DateTime("$curveDate 16:00:00");

    $cum = 0.0;
    //chartjs expects milliseconds since epoch, so *= 1000
    $points = [
        [$sessionStart->getTimestamp() * 1000, $cum]
    ];

    foreach ($completedTrades as $ct) {
        $t = new DateTime($ct['close_dtg']);

        $cum += (float)$ct['dPnL'];
        $points[] = [$t->getTimestamp() * 1000, $cum];
    }

    return $points;
}

$equitySeries = computeEquitySeries($completedTrades);

// Build return histogram
$edges = array_merge([-INF], [-2.0, -1.5, -1.0, -0.5, 0.0, 0.5, 1.0, 1.5, 2.0], [INF]);
$labels = [];
foreach (array_keys($edges) as $i) {
    if ($i === 0 && is_infinite($edges[$i]) && $edges[$i] < 0) {
        $labels[] = "≤ " . sprintf('%.1f', $edges[$i + 1]) . "%";
    } elseif ($i === count($edges) - 2) {
        $labels[] = "≥ " . sprintf('%.1f', $edges[$i]) . "%";
    } elseif ($i < count($edges) - 1) {
        $low = $edges[$i];
        $high = $edges[$i + 1];
        if (is_finite($low) && is_finite($high)) {
            $labels[] = sprintf('%.1f', $low) . " – " . sprintf('%.1f', $high) . "%";
        }
    }
}

$counts = array_fill(0, count($labels), 0);
foreach ($completedTrades as $ct) {
    $ret = (($ct['close_price'] - $ct['open_price']) / $ct['open_price']) * 100;
    foreach ($edges as $i => $low) {
        if ($i === count($edges) - 1) break;
        $high = $edges[$i + 1];
        if ($ret >= $low && $ret < $high) {
            $counts[$i]++;
            break;
        }
    }
}
$returnHistogram = ['labels' => $labels, 'counts' => $counts];

?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard</title>
    <link rel="stylesheet" href="/css/styles.css" />
    <script>
        window.EQUITY_SERIES = <?= json_encode($equitySeries) ?>;
    </script>
    <script>
        window.RETURN_HISTOGRAM = <?= json_encode($returnHistogram) ?>;
    </script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/luxon@3"></script>
    <script src="https://cdn.jsdelivr.net/npm/chartjs-adapter-date-luxon"></script>
    <script src="/js/charts.js"></script>
</head>

<body>
    <div class="dashboard">
        <h1>Zalgo v1.30a Performance</h1>
        <label for="datePicker">Select Date:</label>
        <input type="date" id="datePicker" value="<?= htmlspecialchars($date) ?>">
        <script>
            document.getElementById('datePicker').addEventListener('change', function() {
                const d = this.value;
                window.location.href = '/' + d;
            });
        </script>
        <div class="charts-container">
            <div class="equity-curve chart-item">
                <canvas id="equityChart"></canvas>
            </div>

            <div class="histogram-container chart-item">
                <canvas id="returnHistogram"></canvas>
            </div>

            <div class="chart-item histogram-legend">
                <table class="legend-table">
                    <thead>
                        <tr>
                            <th>Class</th>
                            <th>Daily Return Range</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($returnHistogram['labels'] as $idx => $range) : ?>
                            <?php $letter = chr(ord('A') + $idx); ?>
                            <tr>
                                <td><strong><?= htmlspecialchars($letter) ?></strong></td>
                                <td><?= htmlspecialchars($range) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <h2>Transactions</h2>
        <div class="table-container">
            <table class="table">
                <thead>
                    <tr>
                        <?php if (!empty($trades)) : ?>
                            <?php foreach (array_keys($trades[0]) as $col) : ?>
                                <th><?= htmlspecialchars($col) ?></th>
                            <?php endforeach; ?>
                        <?php else : ?>
                            <th>No data available</th>
                        <?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($trades as $row) : ?>
                        <tr>
                            <?php foreach ($row as $cell) : ?>
                                <td><?= htmlspecialchars($cell) ?></td>
                            <?php endforeach; ?>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <h2>Trades</h2>
        <div class="table-container">
            <table class="table">
                <thead>
                    <tr>
                        <th>Symbol</th>
                        <th>Strike</th>
                        <th>Expiry</th>
                        <th>Qty</th>
                        <th>Open Time</th>
                        <th>Close Time</th>
                        <th>Open Price</th>
                        <th>Close Price</th>
                        <th>Total P&amp;L</th>
                        <th>&Delta; P&L</th>
                        <th>&Theta; P&L</th>
                        <th>&Nu; P&L</th>
                        <th>&Gamma; P&L</th>
                        <th>Residual</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!empty($completedTrades)) : ?>
                        <?php foreach ($completedTrades as $ct) : ?>
                            <tr>
                                <td><?= htmlspecialchars($ct['symbol']) ?></td>
                                <td><?= htmlspecialchars($ct['strike']) ?></td>
                                <td><?= htmlspecialchars($ct['expiry']) ?></td>
                                <td><?= htmlspecialchars($ct['qty']) ?></td>
                                <td><?= htmlspecialchars($ct['open_dtg']) ?></td>
                                <td><?= htmlspecialchars($ct['close_dtg']) ?></td>
                                <td><?= htmlspecialchars($ct['open_price']) ?></td>
                                <td><?= htmlspecialchars($ct['close_price']) ?></td>
                                <td class="<?= $ct['dPnL'] >= 0 ? 'pl-positive' : 'pl-negative' ?>">
                                    <?= number_format($ct['dPnL'], 2) ?>
                                <td><?= number_format($ct['deltaPL'], 2) ?></td>
                                <td><?= number_format($ct['thetaPL'], 2) ?></td>
                                <td><?= number_format($ct['vegaPL'], 2) ?></td>
                                <td><?= number_format($ct['gammaPL'], 2) ?></td>
                                <td><?= number_format($ct['residual'], 2) ?></td>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else : ?>
                        <tr>
                            <td colspan="9">No completed trades found.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</body>

</html>
