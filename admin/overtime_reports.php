<?php
session_start();
require_once '../includes/config.php';

if(!isset($_SESSION['user_id'])){
    header("Location: ../login.php");
    exit;
}

// Fetch users for dropdown
$usersStmt = $pdo->query("SELECT id, username, COALESCE(salary,0) AS salary_rate, COALESCE(overtime_rate,0) AS overtime_rate, position FROM users ORDER BY username ASC");
$usersList = $usersStmt->fetchAll(PDO::FETCH_ASSOC);

// Get filters
$selectedUserId = isset($_GET['user_id']) && is_numeric($_GET['user_id']) ? intval($_GET['user_id']) : '';
$start_date = $_GET['start_date'] ?? '';
$end_date = $_GET['end_date'] ?? '';

// Get salary & OT rates for selected user
$selectedSalaryRate = 0;
$selectedOvertimeRate = 0;
$selectedUserName = '';
$selectedUserPosition = 'N/A';
if($selectedUserId){
    $stmt = $pdo->prepare("SELECT username, COALESCE(salary,0) AS salary_rate, COALESCE(overtime_rate,0) AS overtime_rate, position FROM users WHERE id=?");
    $stmt->execute([$selectedUserId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if($row){
        $selectedUserName = $row['username'];
        $selectedSalaryRate = floatval($row['salary_rate']);
        $selectedOvertimeRate = floatval($row['overtime_rate']);
        $selectedUserPosition = $row['position'] ?: 'N/A';
    }
}

// Build WHERE clause
$where = [];
$params = [];
if($selectedUserId){ $where[] = "dl.user_id=:user_id"; $params[':user_id']=$selectedUserId; }
if($start_date){ $where[] = "DATE(dl.timestamp)>=:start_date"; $params[':start_date']=$start_date; }
if($end_date){ $where[] = "DATE(dl.timestamp)<=:end_date"; $params[':end_date']=$end_date; }
$whereSQL = !empty($where) ? 'WHERE '.implode(' AND ', $where) : '';

// Fetch all DTR logs
$stmt = $pdo->prepare("SELECT dl.*, l.name AS location_name FROM dtr_logs dl LEFT JOIN tagged_locations l ON dl.location_id=l.id $whereSQL ORDER BY dl.timestamp ASC");
$stmt->execute($params);
$allLogs = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Separate time_in and time_out
$timeInArr = [];
$timeOutArr = [];
foreach($allLogs as $log){
    if($log['action']=='time_in') $timeInArr[]=$log;
    elseif($log['action']=='time_out') $timeOutArr[]=$log;
}

// Pair time_in and time_out
$logs = [];
foreach($timeInArr as $inLog){
    $timeIn = new DateTime($inLog['timestamp']);
    $locationIn = $inLog['location_name'] ?? 'Unknown';
    $pairedTimeOut = null;
    $pairedOutIndex = null;

    foreach($timeOutArr as $idx => $outLog){
        $timeOutCandidate = new DateTime($outLog['timestamp']);
        if($timeOutCandidate >= $timeIn){
            $pairedTimeOut = $outLog;
            $pairedOutIndex = $idx;
            break;
        }
    }

    if($pairedTimeOut){
        unset($timeOutArr[$pairedOutIndex]);
        $timeOut = new DateTime($pairedTimeOut['timestamp']);

        // Only include rows with time_out after 5pm
        if($timeOut->format('H:i') <= '17:00') continue;

        $locationOut = $pairedTimeOut['location_name'] ?? $locationIn;
        $workedSeconds = $timeOut->getTimestamp() - $timeIn->getTimestamp();

        // Subtract lunch break if overlapping
        $lunchStart = new DateTime($timeIn->format('Y-m-d').' 12:00:00');
        $lunchEnd = new DateTime($timeIn->format('Y-m-d').' 13:00:00');
        if($timeIn < $lunchEnd && $timeOut > $lunchStart){
            $overlapStart = $timeIn > $lunchStart?$timeIn:$lunchStart;
            $overlapEnd = $timeOut < $lunchEnd?$timeOut:$lunchEnd;
            $workedSeconds -= ($overlapEnd->getTimestamp()-$overlapStart->getTimestamp());
        }

        $hoursDecimal = round($workedSeconds/3600,2);
        $normalHours = min($hoursDecimal,8);
        $otHours = max($hoursDecimal-8,0);
        $computedSalary = round(($normalHours*$selectedSalaryRate)+($otHours*$selectedOvertimeRate),2);

        $logs[]=[
            'in_id'=>intval($inLog['id']),
            'out_id'=>intval($pairedTimeOut['id']),
            'date'=>$timeIn->format('Y-m-d'),
            'time_in'=>$timeIn->format('H:i'),
            'time_out'=>$timeOut->format('H:i'),
            'hours_decimal'=>number_format($hoursDecimal,2,'.',''),
            'salary'=>number_format($computedSalary,2,'.',''),
            'location_in'=>$locationIn,
            'location_out'=>$locationOut,
            'ot_hours'=>$otHours
        ];
    }
}

// Remaining unmatched time_outs
foreach($timeOutArr as $outLog){
    $timeOut = new DateTime($outLog['timestamp']);
    if($timeOut->format('H:i') <= '17:00') continue; // skip if not overtime
    $logs[]=[
        'in_id'=>null,
        'out_id'=>intval($outLog['id']),
        'date'=>$timeOut->format('Y-m-d'),
        'time_in'=>'',
        'time_out'=>$timeOut->format('H:i'),
        'hours_decimal'=>'0.00',
        'salary'=>'0.00',
        'location_in'=>'N/A',
        'location_out'=>$outLog['location_name'] ?? 'Unknown',
        'ot_hours'=>'0'
    ];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Overtime DTR Report</title>
<script src="https://cdn.tailwindcss.com"></script>
<script src="https://unpkg.com/feather-icons"></script>
</head>
<body class="bg-gray-100 text-gray-800">
<div class="flex min-h-screen">
<!-- Sidebar -->
  <aside class="w-64 bg-gradient-to-br from-[#667eea] to-[#764ba2] text-white shadow-2xl">
    <div class="px-6 py-6 shadow-md border-b border-gray-700 flex items-center space-x-3">
      <img src="../assets/logo.jpg" alt="Logo" class="w-10 h-10 rounded-full" />
      <h2 class="text-2xl font-bold tracking-wide text-white">Monitoring</h2>
  </div>
  <nav class="px-6 py-8 space-y-6">
    <a href="dashboard.php" class="block text-base font-medium text-white hover:bg-gradient-to-r hover:from-red-500 hover:to-purple-600 px-3 py-2 rounded">📊 Dashboard</a>
    <a href="user_management.php" class="block text-base font-medium text-white hover:bg-gradient-to-r hover:from-red-500 hover:to-purple-600 px-3 py-2 rounded">👥 User Management</a>
    <a href="create_group.php" class="block text-base font-medium text-white hover:bg-gradient-to-r hover:from-red-500 hover:to-purple-600 px-3 py-2 rounded">🗂️ Create Group</a>
    <a href="create_task.php" class="block text-base font-medium text-white hover:bg-gradient-to-r hover:from-red-500 hover:to-purple-600 px-3 py-2 rounded">✅ Assign Task</a>


    <div class="relative w-full">
      <button id="dropdownButton" class="w-full flex justify-between items-center text-white bg-gradient-to-r from-red-500 to-purple-600 px-3 py-2 rounded font-medium focus:outline-none">
        📤 Individual Reports
        <svg class="w-4 h-4 transition-transform duration-200" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
        </svg>
      </button>

      <ul id="dropdownMenu" class="hidden bg-gradient-to-r from-red-600 to-purple-700 rounded mt-1 w-full text-white shadow-inner">
        <li>
          <a href="individual_reports.php" class="block px-4 py-2 hover:bg-red-500 hover:to-purple-600 transition-colors duration-300 rounded">
            Regular Reports
          </a>
        </li>
        <li>
          <a href="overtime_reports.php" class="block px-4 py-2 hover:bg-red-500 hover:to-purple-600 transition-colors duration-300 rounded">
            Overtime Reports
          </a>
        </li>
      </ul>
    </div>

    <hr class="my-4 border-gray-600" />
    <a href="../includes/logout.php" class="block text-base font-semibold text-gray-200 hover:text-red-200 transition duration-500">🚪 Logout</a>
  </nav>
</aside>

<script>
const dropdownButton = document.getElementById('dropdownButton');
const dropdownMenu = document.getElementById('dropdownMenu');

dropdownButton.addEventListener('click', () => {
  dropdownMenu.classList.toggle('hidden');
  dropdownButton.querySelector('svg').classList.toggle('rotate-180');
});

document.addEventListener('click', (e) => {
  if (!dropdownButton.contains(e.target) && !dropdownMenu.contains(e.target)) {
    dropdownMenu.classList.add('hidden');
    dropdownButton.querySelector('svg').classList.remove('rotate-180');
  }
});
</script>

<!-- Main content -->
<div class="flex-1 flex flex-col">
<header class="bg-white shadow px-6 py-4 flex justify-between items-center">
<h1 class="text-xl font-semibold">Overtime DTR Report</h1>
<div class="text-sm text-gray-600">Hello, <?= htmlspecialchars($_SESSION['username']); ?></div>
</header>
<main class="p-6">
<div class="bg-white rounded-xl shadow-md p-6 overflow-x-auto">

<!-- Filters -->
<form method="GET" class="flex flex-wrap items-end gap-4 mb-6">
  <div class="flex flex-col">
    <label class="text-gray-700 font-medium mb-1">User:</label>
    <select name="user_id" onchange="this.form.submit()" class="px-3 py-2 border border-gray-300 rounded-md focus:ring-2 focus:ring-purple-500 focus:outline-none">
      <option value="">-- Select User --</option>
      <?php foreach($usersList as $u): ?>
        <option value="<?= $u['id'] ?>" <?= ($selectedUserId==$u['id'])?'selected':'' ?>><?= htmlspecialchars($u['username']) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="flex flex-col">
    <label class="text-gray-700 font-medium mb-1">From:</label>
    <input type="date" name="start_date" value="<?= htmlspecialchars($start_date) ?>" onchange="this.form.submit()" class="px-3 py-2 border border-gray-300 rounded-md focus:ring-2 focus:ring-purple-500 focus:outline-none">
  </div>
  <div class="flex flex-col">
    <label class="text-gray-700 font-medium mb-1">To:</label>
    <input type="date" name="end_date" value="<?= htmlspecialchars($end_date) ?>" onchange="this.form.submit()" class="px-3 py-2 border border-gray-300 rounded-md focus:ring-2 focus:ring-purple-500 focus:outline-none">
  </div>
</form>

<!-- Buttons -->
<div class="mb-4 flex justify-end gap-2">
<button onclick="printDTR()" class="bg-green-600 text-white px-4 py-2 rounded hover:bg-green-700">🖨️ Print Report</button>
<button onclick="exportCSV()" class="bg-gray-600 text-white px-4 py-2 rounded hover:bg-gray-700">⬇️ Export CSV</button>
</div>

<div id="printHeader" class="hidden">
<h2 class="text-2xl font-bold">DIS COMPANY</h2>
<p><?= htmlspecialchars($selectedUserPosition) ?></p>
<p>User: <?= htmlspecialchars($selectedUserName ?: 'All Users') ?></p>
<hr class="my-2 border-black">
</div>

<!-- Table -->
<table id="dtrTable" class="min-w-full border border-gray-200 rounded-lg">
<thead class="bg-gray-100 text-gray-700">
<tr>
<th class="p-2 border">#</th>
<th class="p-2 border">Date</th>
<th class="p-2 border">Time In</th>
<th class="p-2 border">Time Out</th>
<th class="p-2 border">Hours (hrs)</th>
<th class="p-2 border">Salary (₱)</th>
<th class="p-2 border">Location In</th>
<th class="p-2 border">Location Out</th>
<th class="p-2 border">OT Hours</th>
</tr>
</thead>
<tbody>
<?php
$counter=1;
$totalHours=0;
$totalSalary=0;
$totalOT=0;
foreach($logs as $row):
    $totalHours+=floatval($row['hours_decimal']);
    $totalSalary+=floatval($row['salary']);
    $totalOT+=floatval($row['ot_hours']);
?>
<tr class="even:bg-gray-50">
<td class="p-2 border"><?= $counter++ ?></td>
<td class="p-2 border"><?= $row['date'] ?></td>
<td class="p-2 border"><?= $row['time_in'] ?></td>
<td class="p-2 border"><?= $row['time_out'] ?></td>
<td class="p-2 border"><?= $row['hours_decimal'] ?></td>
<td class="p-2 border"><?= $row['salary'] ?></td>
<td class="p-2 border"><?= htmlspecialchars($row['location_in']) ?></td>
<td class="p-2 border"><?= htmlspecialchars($row['location_out']) ?></td>
<td class="p-2 border"><?= $row['ot_hours'] ?></td>
</tr>
<?php endforeach; ?>
</tbody>
<tfoot class="bg-gray-100 font-semibold">
<tr>
<td colspan="4" class="text-right p-2 border">Total:</td>
<td id="totalHours" class="p-2 border"><?= number_format($totalHours,2) ?></td>
<td id="totalSalary" class="p-2 border"><?= number_format($totalSalary,2) ?></td>
<td colspan="2" class="border"></td>
<td id="totalOT" class="p-2 border"><?= number_format($totalOT,2) ?></td>
</tr>
</tfoot>
</table>

</div>
</main>
</div>
</div>

<script>
function printDTR(){
    document.getElementById('printHeader').classList.remove('hidden');
    window.print();
    document.getElementById('printHeader').classList.add('hidden');
}

function exportCSV(){
    let csv='Date,Time In,Time Out,Hours,Salary,Location In,Location Out,OT Hours\n';
    document.querySelectorAll('#dtrTable tbody tr').forEach(tr=>{
        const tds=tr.querySelectorAll('td');
        if(tds.length>1){
            csv+=[...tds].slice(1).map(td=>td.textContent.trim()).join(',')+'\n';
        }
    });
    const blob=new Blob([csv],{type:'text/csv'});
    const a=document.createElement('a');
    a.href=URL.createObjectURL(blob);
    a.download='overtime_report.csv';
    document.body.appendChild(a);
    a.click();
    a.remove();
}
</script>
</body>
</html>
