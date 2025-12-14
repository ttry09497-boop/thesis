<?php
session_start();
require_once '../includes/config.php';

// Fetch all users including position, salary, and overtime rate
$stmt = $pdo->query("SELECT * FROM users ORDER BY id DESC");
$users = $stmt->fetchAll();
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>User Management</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <script src="https://unpkg.com/feather-icons"></script>
  <style>
    .firework { position: relative; overflow: hidden; }
    .firework::after {
      content: ""; position: absolute; top:50%; left:50%; width:10px; height:10px;
      background: radial-gradient(circle, red, orange, yellow, white);
      border-radius: 50%;
      animation: explode 0.6s ease-out forwards; z-index:10;
    }
    @keyframes explode { 0% { width:0;height:0;opacity:1;transform:translate(-50%,-50%) scale(1);} 
                         100% { width:300px;height:300px;opacity:0;transform:translate(-50%,-50%) scale(2);} }
    @keyframes fadeScaleIn { 0% {opacity:0;transform:scale(0.9);} 100% {opacity:1;transform:scale(1);} }
    .modal-animate { animation: fadeScaleIn 0.3s ease-out; }
    .fade-out { opacity:1; transition: opacity 1s ease-out; }
    .fade-out.hide { opacity:0; }
  </style>
  <script>
    // Delete user
    function deleteUser(button, userId) {
      if(!confirm("Are you sure you want to delete this user?")) return;
      button.classList.add("firework");
      setTimeout(() => {
        fetch(`delete_user.php?id=${userId}`)
          .then(res => res.json())
          .then(data => {
            if(data.success) button.closest("tr").remove();
            else alert("Failed to delete user.");
          })
          .catch(()=>alert("Error occurred."));
      }, 500);
    }

    // Open Edit Modal
    function openEditModal(id, username, phone, role, position, salary, overtime){
      document.getElementById('edit-user-id').value = id;
      document.getElementById('edit-username').value = username;
      document.getElementById('edit-phone').value = phone;
      document.getElementById('edit-role').value = role;
      document.getElementById('edit-position').value = position;
      document.getElementById('edit-salary').value = salary;
      document.getElementById('edit-overtime').value = overtime;
      const modal = document.getElementById('editModal');
      modal.classList.remove('hidden');
      modal.querySelector('.modal-content').classList.add('modal-animate');
    }

    function closeEditModal(){ 
      document.getElementById('editModal').classList.add('hidden'); 
    }

    window.addEventListener('DOMContentLoaded', () => {
      const alert = document.getElementById('successAlert');
      if(alert){ setTimeout(()=>{ alert.classList.add('hide'); setTimeout(()=>alert.remove(),1000); },1000); }

      if(window.location.search.includes('success')){
        setTimeout(()=>{
          const url = new URL(window.location.href);
          url.searchParams.delete('success');
          window.history.replaceState({}, document.title, url.pathname);
        },1500);
      }
    });
  </script>
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
      <a href="user_management.php" class="block text-base font-medium text-white bg-gradient-to-r from-red-500 to-purple-600 px-3 py-2 rounded">👥 User Management</a>
      <a href="create_group.php" class="block text-base font-medium text-white hover:bg-gradient-to-r hover:from-red-500 hover:to-purple-600 px-3 py-2 rounded">🗂️ Create Group</a>
      <a href="create_task.php" class="block text-base font-medium text-white hover:bg-gradient-to-r hover:from-red-500 hover:to-purple-600 px-3 py-2 rounded">✅ Assign Task</a>
       <div class="relative w-full">
  <button id="dropdownButton" 
          class="w-full flex justify-between items-center px-3 py-2 rounded font-medium transition duration-200
          <?php echo ($current_page === 'individual_reports.php' || $current_page === 'overtime_reports.php') 
                    ? 'bg-gradient-to-r from-red-500 to-purple-600 text-white shadow-lg' 
                    : 'text-white hover:bg-gradient-to-r hover:from-red-500 hover:to-purple-600'; ?>">
    📤 Individual Reports
    <svg class="w-4 h-4 transition-transform duration-200" fill="none" stroke="currentColor" viewBox="0 0 24 24">
      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
    </svg>
  </button>
  
  <!-- Dropdown menu -->
  <ul id="dropdownMenu" class="hidden bg-gradient-to-r from-red-600 to-purple-700 rounded mt-1 w-full text-white shadow-inner">
    <li>
      <a href="individual_reports.php" 
         class="block px-4 py-2 rounded transition-colors duration-200
         <?php echo $current_page === 'individual_reports.php' ? 'bg-red-500' : 'hover:bg-red-500'; ?>">
        Regular Reports
      </a>
    </li>
    <li>
      <a href="overtime_reports.php" 
         class="block px-4 py-2 rounded transition-colors duration-200
         <?php echo $current_page === 'overtime_reports.php' ? 'bg-red-500' : 'hover:bg-red-500'; ?>">
        Overtime Reports
      </a>
    </li>
  </ul>
</div>
      <hr class="my-4 border-gray-600" />
      <a href="../includes/logout.php" class="block text-base font-semibold text-red-300 hover:text-red-100">🚪 Logout</a>
    </nav>
  </aside>

  <script>
  const dropdownButton = document.getElementById('dropdownButton');
  const dropdownMenu = document.getElementById('dropdownMenu');

  dropdownButton.addEventListener('click', () => {
    dropdownMenu.classList.toggle('hidden');
  });
</script>

  <!-- Main Content -->
  <main class="flex-1 p-6">
    <header class="bg-white shadow px-6 py-4 mb-6 flex justify-between items-center">
      <h1 class="text-xl font-semibold">👥 User Management</h1>
      <div class="text-sm text-gray-600">Hello, <?php echo $_SESSION['username'] ?? 'Admin'; ?></div>
    </header>

    <!-- Add User Form -->
    <div class="bg-white p-6 rounded-xl shadow-md mb-8">
      <h3 class="text-xl font-semibold mb-4">➕ Add New User</h3>
      <form method="POST" action="add_user.php" class="grid grid-cols-1 md:grid-cols-3 gap-4">
        <div>
          <label for="username" class="block text-sm font-medium">Username</label>
          <input type="text" name="username" id="username" required class="w-full border rounded px-3 py-2 mt-1" />
        </div>
        <div>
          <label for="phone" class="block text-sm font-medium">Phone Number</label>
          <input type="text" name="phone" id="phone" required class="w-full border rounded px-3 py-2 mt-1" />
        </div>
        <div>
          <label for="password" class="block text-sm font-medium">Password</label>
          <input type="password" name="password" id="password" required class="w-full border rounded px-3 py-2 mt-1" />
        </div>
        <div>
          <label for="role" class="block text-sm font-medium">System Role</label>
          <select name="role" id="role" class="w-full border rounded px-3 py-2 mt-1">
            <option value="user">User</option>
            <option value="admin">Admin</option>
          </select>
        </div>
        <div>
          <label for="position" class="block text-sm font-medium">Company Position</label>
          <input type="text" name="position" id="position" placeholder="e.g. Technician, Staff" required class="w-full border rounded px-3 py-2 mt-1" />
        </div>
        <div>
          <label for="salary" class="block text-sm font-medium">Rate Per Hour</label>
          <input type="number" name="salary" id="salary" placeholder="Enter rate per hour" required class="w-full border rounded px-3 py-2 mt-1" />
        </div>
        <div>
          <label for="overtime_rate" class="block text-sm font-medium">Overtime Rate Per Hour</label>
          <input type="number" name="overtime_rate" id="overtime_rate" placeholder="Enter OT rate per hour" required class="w-full border rounded px-3 py-2 mt-1" />
        </div>
        <div class="md:col-span-3">
          <button type="submit" class="mt-2 bg-blue-600 text-white px-6 py-2 rounded hover:bg-blue-700">➕ Add User</button>
        </div>
      </form>
      <?php if(isset($_GET['success'])): ?>
        <p id="successAlert" class="text-green-600 mt-4 fade-out">✅ User added successfully!</p>
      <?php endif; ?>
    </div>

    <!-- User List Table -->
    <div class="bg-white p-6 rounded-xl shadow-md">
      <h3 class="text-xl font-semibold mb-4">📋 Existing Users</h3>
      <div class="overflow-x-auto">
        <table class="min-w-full divide-y divide-gray-200">
          <thead class="bg-gray-50">
            <tr>
              <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">ID</th>
              <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Username</th>
              <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Phone</th>
              <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Role</th>
              <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Position</th>
              <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Rate Per Hour</th>
              <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">OT Rate</th>
              <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
            </tr>
          </thead>
          <tbody class="bg-white divide-y divide-gray-200">
            <?php foreach($users as $user): ?>
            <tr>
              <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">#<?php echo $user['id']; ?></td>
              <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900"><?php echo htmlspecialchars($user['username']); ?></td>
              <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900"><?php echo htmlspecialchars($user['phone']); ?></td>
              <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500"><?php echo $user['role']; ?></td>
              <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900"><?php echo htmlspecialchars($user['position'] ?? ''); ?></td>
              <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">₱<?php echo number_format($user['salary'] ?? 0,2); ?></td>
              <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">₱<?php echo number_format($user['overtime_rate'] ?? 0,2); ?></td>
              <td class="px-6 py-4 whitespace-nowrap text-sm space-x-2">
                <button onclick="openEditModal(
                  '<?php echo $user['id']; ?>',
                  '<?php echo addslashes($user['username']); ?>',
                  '<?php echo addslashes($user['phone']); ?>',
                  '<?php echo $user['role']; ?>',
                  '<?php echo addslashes($user['position']); ?>',
                  '<?php echo $user['salary']; ?>',
                  '<?php echo $user['overtime_rate']; ?>'
                )"
                  class="inline-block px-3 py-1 bg-gradient-to-r from-green-500 to-emerald-600 text-white rounded-lg text-xs font-medium hover:from-green-600 hover:to-emerald-700 transition">✏️ Edit</button>
                <button onclick="deleteUser(this, <?php echo $user['id']; ?>)" 
                  class="inline-block px-3 py-1 bg-gradient-to-r from-red-500 to-rose-600 text-white rounded-lg text-xs font-medium hover:from-red-600 hover:to-rose-700 transition">🗑️ Delete</button>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>

    <!-- Edit User Modal -->
    <div id="editModal" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center hidden z-50">
      <div class="modal-content bg-white p-6 rounded-xl shadow-xl w-full max-w-md">
        <h2 class="text-xl font-bold mb-4">✏️ Edit User</h2>
        <form action="edit_user.php" method="POST" class="space-y-4">
          <input type="hidden" name="id" id="edit-user-id" />
          <div>
            <label class="block text-sm font-medium">Username</label>
            <input type="text" name="username" id="edit-username" class="w-full border rounded px-3 py-2" required />
          </div>
          <div>
            <label class="block text-sm font-medium">Phone</label>
            <input type="text" name="phone" id="edit-phone" class="w-full border rounded px-3 py-2" required />
          </div>
          <div>
            <label class="block text-sm font-medium">Role</label>
            <select name="role" id="edit-role" class="w-full border rounded px-3 py-2">
              <option value="user">User</option>
              <option value="admin">Admin</option>
            </select>
          </div>
          <div>
            <label class="block text-sm font-medium">Company Position</label>
            <input type="text" name="position" id="edit-position" class="w-full border rounded px-3 py-2" required />
          </div>
          <div>
            <label class="block text-sm font-medium">Rate Per Hour</label>
            <input type="number" name="salary" id="edit-salary" class="w-full border rounded px-3 py-2" required />
          </div>
          <div>
            <label class="block text-sm font-medium">Overtime Rate Per Hour</label>
            <input type="number" name="overtime_rate" id="edit-overtime" class="w-full border rounded px-3 py-2" required />
          </div>
          <div class="flex justify-end space-x-2">
            <button type="button" onclick="closeEditModal()" class="px-4 py-2 bg-gray-300 rounded hover:bg-gray-400">Cancel</button>
            <button type="submit" class="px-4 py-2 bg-blue-600 text-white rounded hover:bg-blue-700">Save</button>
          </div>
        </form>
      </div>
    </div>

  </main>
</div>
<script>feather.replace();</script>
</body>
</html>
