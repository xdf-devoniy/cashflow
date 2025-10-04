<?php
session_start();
if (!isset($_SESSION['authenticated']) || $_SESSION['authenticated'] !== true) {
    header('Location: index.php');
    exit();
}

include 'db.php';

function format_number($number) {
    return number_format($number);
}

$start_date = isset($_GET['start_date']) ? $_GET['start_date'] : date('Y-m-d', strtotime('-1 day'));
$end_date = isset($_GET['end_date']) ? $_GET['end_date'] : date('Y-m-d');

$sql = "SELECT * FROM transactions WHERE date BETWEEN '$start_date' AND '$end_date' ORDER BY date ASC";
$result = $conn->query($sql);

$cash_in = [];
$cash_out = [];
$dates = [];

if ($result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        $date = $row['date'];
        if (!in_array($date, $dates)) {
            $dates[] = $date;
            $cash_in[$date] = 0;
            $cash_out[$date] = 0;
        }
        if ($row['cash_in']) {
            $cash_in[$date] += $row['payment'];
        } else {
            $cash_out[$date] += $row['payment'];
        }
    }
}

$conn->close();
?>
<!DOCTYPE html>
<html>
<head>
    <title>Statistics</title>
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.3.1/css/bootstrap.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        /* Full page overlay */
        #loadingOverlay {
            position: fixed;
            width: 100%;
            height: 100%;
            top: 0;
            left: 0;
            background: rgba(255, 255, 255, 0.8);
            z-index: 9999;
            display: flex;
            justify-content: center;
            align-items: center;
        }

        /* Spinner */
        .spinner-border {
            width: 3rem;
            height: 3rem;
            border-width: 0.4em;
        }
    </style>
</head>
<body>
    <div id="loadingOverlay">
    <div class="spinner-border text-primary" role="status">
        <span class="sr-only">Loading...</span>
    </div>
</div>
<div class="container">
    <h2 class="my-4 text-center">Statistics</h2>
    <form method="get" class="form-inline mb-3">
        <label for="start_date" class="mr-2">Start Date:</label>
        <input type="date" id="start_date" name="start_date" class="form-control mr-3" value="<?= $start_date ?>" required>
        <label for="end_date" class="mr-2">End Date:</label>
        <input type="date" id="end_date" name="end_date" class="form-control mr-3" value="<?= $end_date ?>" required>
        <button type="submit" class="btn btn-primary">Filter</button>
    </form>
    <canvas id="myChart" width="400" height="200"></canvas>
    <a href="index.php" class="btn btn-secondary mt-3">Back to Main Page</a>
</div>

<script>

    document.addEventListener("DOMContentLoaded", function() {
        document.getElementById('loadingOverlay').style.display = 'none';
    });


document.addEventListener("DOMContentLoaded", function() {
    var ctx = document.getElementById('myChart').getContext('2d');
    var myChart = new Chart(ctx, {
        type: 'bar',
        data: {
            labels: <?php echo json_encode($dates); ?>,
            datasets: [
                {
                    label: 'Total Cash In',
                    data: <?php echo json_encode(array_values($cash_in)); ?>,
                    backgroundColor: 'rgba(75, 192, 192, 0.2)',
                    borderColor: 'rgba(75, 192, 192, 1)',
                    borderWidth: 1
                },
                {
                    label: 'Total Cash Out',
                    data: <?php echo json_encode(array_values($cash_out)); ?>,
                    backgroundColor: 'rgba(255, 99, 132, 0.2)',
                    borderColor: 'rgba(255, 99, 132, 1)',
                    borderWidth: 1
                }
            ]
        },
        options: {
            scales: {
                y: {
                    beginAtZero: true
                }
            }
        }
    });
});
</script>
</body>
</html>
