<?php
session_start();
require_once '../includes/config.php';

if(!isset($_SESSION['user_id'])){
    header("Location: ../login.php");
    exit;
}

// Fetch users for dropdown
$usersStmt = $pdo->query("SELECT id, username FROM users ORDER BY username ASC");
$usersList = $usersStmt->fetchAll(PDO::FETCH_ASSOC);

// Get filters
$selectedUserId = isset($_GET['user_id']) && is_numeric($_GET['user_id']) ? intval($_GET['user_id']) : '';
$start_date = $_GET['start_date'] ?? '';
$end_date = $_GET['end_date'] ?? '';

// Get salary & OT rates
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

// Fetch DTR logs
$stmt = $pdo->prepare("SELECT dl.*, l.name AS location_name FROM dtr_logs dl LEFT JOIN tagged_locations l ON dl.location_id=l.id $whereSQL ORDER BY dl.timestamp ASC");
$stmt->execute($params);
$allLogs = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Filter out OT logs and logs after 5 PM
$regularEnd = new DateTime('17:00:00'); // 5:00 PM
$allLogs = array_filter($allLogs, function($log) use ($regularEnd){
    $logTime = new DateTime($log['timestamp']);
    return !in_array($log['action'], ['time_in_ot','time_out_ot']) && $logTime <= $regularEnd;
});


// Pair time_in & time_out
$logs = [];
$timeInArr = [];
$timeOutArr = [];
foreach($allLogs as $log){
    if($log['action']=='time_in') $timeInArr[]=$log;
    elseif($log['action']=='time_out') $timeOutArr[]=$log;
}

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
        $locationOut = $pairedTimeOut['location_name'] ?? $locationIn;

        $workedSeconds = $timeOut->getTimestamp() - $timeIn->getTimestamp();
        $lunchStart = new DateTime($timeIn->format('Y-m-d').' 12:00:00');
        $lunchEnd = new DateTime($timeIn->format('Y-m-d').' 13:00:00');
        if($timeIn < $lunchEnd && $timeOut > $lunchStart){
            $overlapStart = $timeIn > $lunchStart?$timeIn:$lunchStart;
            $overlapEnd = $timeOut<$lunchEnd?$timeOut:$lunchEnd;
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
            'location_out'=>$locationOut
        ];
    } else {
        $logs[]=[
            'in_id'=>intval($inLog['id']),
            'out_id'=>null,
            'date'=>$timeIn->format('Y-m-d'),
            'time_in'=>$timeIn->format('H:i'),
            'time_out'=>'',
            'hours_decimal'=>'0.00',
            'salary'=>'0.00',
            'location_in'=>$locationIn,
            'location_out'=>'N/A'
        ];
    }
}

// Unmatched time_out
foreach($timeOutArr as $outLog){
    $timeOut = new DateTime($outLog['timestamp']);
    $logs[]=[
        'in_id'=>null,
        'out_id'=>intval($outLog['id']),
        'date'=>$timeOut->format('Y-m-d'),
        'time_in'=>'',
        'time_out'=>$timeOut->format('H:i'),
        'hours_decimal'=>'0.00',
        'salary'=>'0.00',
        'location_in'=>'N/A',
        'location_out'=>$outLog['location_name'] ?? 'Unknown'
    ];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Individual DTR Report</title>
<script src="https://cdn.tailwindcss.com"></script>
<script src="https://unpkg.com/feather-icons"></script>
<style>
@media print {
  body * { visibility:hidden; }
  #printHeader, #printHeader *, #dtrTable, #dtrTable * { visibility:visible; }
  #dtrTable { position:absolute; top:180px; left:0; width:100%; border-collapse:collapse; font-size:12pt;}
  #dtrTable th, #dtrTable td { border:1px solid #000; padding:4px 6px; }
  #printHeader { position:absolute; top:0; left:0; width:100%; text-align:center; }
}
.edited { background-color: #fffae6; }
</style>
</head>
<body class="bg-gray-100 font-sans">
<div class="flex h-screen">
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


        <!-- Dropdown for Individual Reports -->
  <div class="relative w-full">
    <button id="dropdownButton" class="w-full flex justify-between items-center text-white bg-gradient-to-r from-red-500 to-purple-600 px-3 py-2 rounded font-medium focus:outline-none">
      📤 Individual Reports
      <svg class="w-4 h-4 transition-transform duration-200" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
      </svg>
    </button>
 <!-- Dropdown Submenu -->
  <ul id="dropdownMenu" class="hidden bg-gradient-to-r from-red-600 to-purple-700 rounded mt-1 w-full text-white shadow-inner">
    <li>
      <a href="individual_reports.php" class="block px-4 py-2 hover:bg-red-500 hover:to-purple-600 transition-colors duration-300 rounded" onclick="applyOTFilter(this)" data-value="1">
        Regular Reports
      </a>
    </li>
    <li>
      <a href="overtime_reports.php" class="block px-4 py-2 hover:bg-red-500 hover:to-purple-600 transition-colors duration-300 rounded" onclick="applyOTFilter(this)" data-value="0">
        Overtime reports
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

  // Optional: close dropdown when clicking outside
  document.addEventListener('click', (e) => {
    if (!dropdownButton.contains(e.target) && !dropdownMenu.contains(e.target)) {
      dropdownMenu.classList.add('hidden');
      dropdownButton.querySelector('svg').classList.remove('rotate-180');
    }
  });

  function applyOTFilter(el) {
    const value = el.getAttribute('data-value');
    console.log('Selected OT filter:', value);
    // Your filter logic here
  }
</script>


<div class="flex-1 flex flex-col">
<header class="bg-white shadow px-6 py-4 flex justify-between items-center">
<h1 class="text-xl font-semibold">Individual DTR Report</h1>
<div class="text-sm text-gray-600">Hello, <?= htmlspecialchars($_SESSION['username']); ?></div>
</header>
<main class="p-6">
<div class="bg-white rounded-xl shadow-md p-6 overflow-x-auto">

<!-- Filters -->
<form method="GET" class="flex items-center space-x-3 mb-4">
<div>
<label>User:</label>
<select name="user_id" onchange="this.form.submit()">
<option value="">--Select User--</option>
<?php foreach($usersList as $u): ?>
<option value="<?= $u['id'] ?>" <?= ($selectedUserId==$u['id'])?'selected':'' ?>><?= htmlspecialchars($u['username']) ?></option>
<?php endforeach; ?>
</select>
</div>
<div>
<label>From:</label>
<input type="date" name="start_date" value="<?= htmlspecialchars($start_date) ?>" onchange="this.form.submit()">
</div>
<div>
<label>To:</label>
<input type="date" name="end_date" value="<?= htmlspecialchars($end_date) ?>" onchange="this.form.submit()">
</div>
</form>

<div class="mb-4 flex justify-end gap-2">
<button onclick="printDTR()" class="bg-green-600 text-white px-4 py-2 rounded hover:bg-green-700">🖨️ Print Report</button>
<button onclick="exportCSV()" class="bg-gray-600 text-white px-4 py-2 rounded hover:bg-gray-700">⬇️ Export CSV</button>
</div>

<div id="printHeader" class="hidden">
<h2 class="text-2xl font-bold">Digital Innovation Sulotion</h2>
<p><?= htmlspecialchars($selectedUserPosition) ?></p>
<p>User: <?= htmlspecialchars($selectedUserName ?: 'All Users') ?></p>
<hr class="my-2 border-black">
</div>

<table id="dtrTable" class="min-w-full border border-gray-200 rounded-lg">
<thead class="bg-gray-100 text-gray-700">
<tr>
<th>#</th><th>Date</th><th>Time In</th><th>Time Out</th><th>Hours (hrs)</th><th>Salary (₱)</th><th>Location In</th><th>Location Out</th><th>Action</th>
</tr>
</thead>
<tbody>
<?php
$counter=1;
$totalHours=0;
$totalSalary=0;
foreach($logs as $row):
    $totalHours+=floatval($row['hours_decimal']);
    $totalSalary+=floatval($row['salary']);
?>
<tr id="row_<?= $row['in_id'] ?>" data-in-id="<?= $row['in_id'] ?>" data-out-id="<?= $row['out_id'] ?>" data-hours="<?= $row['hours_decimal'] ?>" data-salary="<?= $row['salary'] ?>" data-ot="<?= max(floatval($row['hours_decimal'])-8,0) ?>">
<td><?= $counter++ ?></td>
<td><?= $row['date'] ?></td>
<td>
<?php if($row['in_id']): ?>
<input type="time" value="<?= $row['time_in'] ?>" data-date="<?= $row['date'] ?>" class="time_in" onchange="recomputeRow(<?= $row['in_id'] ?>)">
<?php else: ?><?= $row['time_in'] ?><?php endif; ?>
</td>
<td>
<?php if($row['out_id']): ?>
<input type="time" value="<?= $row['time_out'] ?>" data-date="<?= $row['date'] ?>" class="time_out" onchange="recomputeRow(<?= $row['in_id'] ?>)">
<?php else: ?><?= $row['time_out'] ?><?php endif; ?>
</td>
<td><span class="hours"><?= $row['hours_decimal'] ?></span></td>
<td><span class="salary"><?= $row['salary'] ?></span></td>
<td><?= htmlspecialchars($row['location_in']) ?></td>
<td><?= htmlspecialchars($row['location_out']) ?></td>
<td>
<?php if($row['in_id']): ?>
<button onclick="saveRow(<?= $row['in_id'] ?>)" class="bg-blue-600 text-white px-3 py-1 rounded hover:bg-blue-700">Save</button>
<?php endif; ?>
</td>
</tr>
<?php endforeach; ?>
</tbody>
<tfoot class="bg-gray-100 font-semibold">
<tr>
<td colspan="4" class="text-right">Total:</td>
<td id="totalHours"><?= number_format($totalHours,2) ?></td>
<td id="totalSalary"><?= number_format($totalSalary,2) ?></td>
<td colspan="3"></td>
</tr>
</tfoot>
</table>
</div>
</main>
</div>
</div>

<script>
const normalRate = <?= $selectedSalaryRate ?>;
const otRate = <?= $selectedOvertimeRate ?>;
const normalHoursLimit = 8;

function recomputeRow(inId){
    const row = document.getElementById('row_'+inId);
    row.classList.add('edited');

    const tIn = row.querySelector('.time_in');
    const tOut = row.querySelector('.time_out');
    if(!tIn || !tOut) return;

    const date = tIn.dataset.date;
    let dtIn = tIn.value ? new Date(date+'T'+tIn.value+':00') : null;
    let dtOut = tOut.value ? new Date(date+'T'+tOut.value+':00') : null;
    if(!dtIn || !dtOut || dtOut<=dtIn) return;

    let workedSeconds = (dtOut-dtIn)/1000;
    const lunchStart = new Date(date+'T12:00:00');
    const lunchEnd = new Date(date+'T13:00:00');
    if(dtIn<lunchEnd && dtOut>lunchStart){
        const overlapStart = dtIn>lunchStart?dtIn:lunchStart;
        const overlapEnd = dtOut<lunchEnd?dtOut:lunchEnd;
        workedSeconds -= (overlapEnd-overlapStart)/1000;
    }

    const hoursDecimal = workedSeconds/3600;
    const normalHours = Math.min(hoursDecimal, normalHoursLimit);
    const otHours = Math.max(hoursDecimal-normalHoursLimit,0);
    const salary = normalHours*normalRate + otHours*otRate;

    row.querySelector('.hours').textContent = hoursDecimal.toFixed(2);
    row.querySelector('.salary').textContent = salary.toFixed(2);
    row.dataset.hours = hoursDecimal.toFixed(2);
    row.dataset.salary = salary.toFixed(2);
    row.dataset.ot = otHours.toFixed(2);

    updateTotals();
}

function saveRow(inId){
    const row = document.getElementById('row_'+inId);
    const tIn = row.querySelector('.time_in');
    const tOut = row.querySelector('.time_out');
    if(!tIn.value || !tOut.value){ alert('Please enter both Time In and Time Out'); return; }

    fetch('update_time.php',{
        method:'POST',
        headers:{'Content-Type':'application/json'},
        body:JSON.stringify({
            in_id: parseInt(row.dataset.inId),
            out_id: parseInt(row.dataset.outId),
            time_in: tIn.dataset.date+' '+tIn.value+':00',
            time_out: tOut.dataset.date+' '+tOut.value+':00',
            hours_decimal: parseFloat(row.dataset.hours || 0),
            salary: parseFloat(row.dataset.salary || 0),
            ot_salary: parseFloat(row.dataset.ot || 0)
        })
    })
    .then(res=>res.json())
    .then(data=>{
        if(data.success){
            row.classList.remove('edited');
            alert('Saved successfully!');
        } else { alert('Save failed: '+data.message); }
    })
    .catch(err=>alert('AJAX error: '+err));
}

function updateTotals(){
    let totalH=0,totalS=0;
    document.querySelectorAll('tr[data-in-id]').forEach(r=>{
        totalH+=parseFloat(r.dataset.hours||0);
        totalS+=parseFloat(r.dataset.salary||0);
    });
    document.getElementById('totalHours').textContent = totalH.toFixed(2);
    document.getElementById('totalSalary').textContent = totalS.toFixed(2);
}

function printDTR(){
    document.getElementById('printHeader').classList.remove('hidden');
    window.print();
    document.getElementById('printHeader').classList.add('hidden');
}

function exportCSV(){
    let csv='Date,Time In,Time Out,Hours,Salary,Location In,Location Out\n';
    document.querySelectorAll('#dtrTable tbody tr').forEach(tr=>{
        const tds=tr.querySelectorAll('td');
        if(tds.length>1){
            csv+=[
                tds[1].textContent.trim(),
                tds[2].querySelector('input')?tds[2].querySelector('input').value:tds[2].textContent.trim(),
                tds[3].querySelector('input')?tds[3].querySelector('input').value:tds[3].textContent.trim(),
                tds[4].textContent.trim(),
                tds[5].textContent.trim(),
                tds[6].textContent.trim(),
                tds[7].textContent.trim()
            ].join(',')+'\n';
        }
    });
    const blob=new Blob([csv],{type:'text/csv'});
    const a=document.createElement('a');
    a.href=URL.createObjectURL(blob);
    a.download='dtr_report.csv';
    document.body.appendChild(a);
    a.click();
    a.remove();
}

function applyOTFilter(select){
    const otValue = select.value;
    const urlParams = new URLSearchParams(window.location.search);

    if(otValue === ''){
        urlParams.delete('ot_filter');
    } else {
        urlParams.set('ot_filter', otValue);
    }

    window.location.search = urlParams.toString(); // reload page with filter
}

feather.replace();
</script>
</body>
</html>
