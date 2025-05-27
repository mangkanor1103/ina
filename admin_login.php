<?php
// filepath: c:\xampp\htdocs\ina\admin_login.php
include 'includes/header.php';

// Redirect if already logged in as admin
if (is_logged_in() && has_role('admin')) {
    redirect("admin_dashboard.php");
}

// Check if last_login column exists in users table, if not add it
$last_login_exists = $conn->query("SHOW COLUMNS FROM users LIKE 'last_login'")->num_rows > 0;
if (!$last_login_exists) {
    $conn->query("ALTER TABLE users ADD COLUMN last_login DATETIME NULL");
}

$errors = [];

// Process login form
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = clean_input($_POST['username']);
    $password = clean_input($_POST['password']);
    
    // Validate credentials
    if (empty($username) || empty($password)) {
        $errors[] = "Both username and password are required.";
    } else {
        $stmt = $conn->prepare("SELECT * FROM users WHERE (username = ? OR email = ?) AND role = 'admin'");
        $stmt->bind_param("ss", $username, $username);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows === 0) {
            $errors[] = "Invalid administrator credentials.";
        } else {
            $user = $result->fetch_assoc();
            
            // Verify password
            if (password_verify($password, $user['password'])) {
                // Set session variables
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['username'] = $user['username'];
                $_SESSION['role'] = $user['role'];
                $_SESSION['logged_in'] = true;
                
                // Record login time (only if column exists)
                if ($last_login_exists || $conn->query("SHOW COLUMNS FROM users LIKE 'last_login'")->num_rows > 0) {
                    $stmt = $conn->prepare("UPDATE users SET last_login = NOW() WHERE id = ?");
                    $stmt->bind_param("i", $user['id']);
                    $stmt->execute();
                }
                
                // Log activity if table exists
                $activity_table_exists = $conn->query("SHOW TABLES LIKE 'activity_log'")->num_rows > 0;
                if ($activity_table_exists) {
                    $activity = "Admin login";
                    $stmt = $conn->prepare("
                        INSERT INTO activity_log (user_id, activity_type, description, created_at) 
                        VALUES (?, 'admin_login', ?, NOW())
                    ");
                    $stmt->bind_param("is", $user['id'], $activity);
                    $stmt->execute();
                }
                
                set_flash_message("success", "Welcome to the admin dashboard!");
                redirect("admin_dashboard.php");
            } else {
                $errors[] = "Invalid password.";
            }
        }
    }
}
?>

<div class="min-h-screen flex items-center justify-center bg-gray-50 py-12 px-4 sm:px-6 lg:px-8 fade-in">
    <div class="max-w-md w-full space-y-8 animate__animated animate__fadeInUp">
        <div>
            <div class="mx-auto h-20 w-20 bg-green-100 rounded-full flex items-center justify-center mb-6">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-10 w-10 text-green-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z" />
                </svg>
            </div>
            <h2 class="mt-6 text-center text-3xl font-extrabold text-green-800">
                Administrator Login
            </h2>
            <p class="mt-2 text-center text-sm text-gray-600">
                Access the secure admin dashboard
            </p>
        </div>
        
        <?php if (!empty($errors)): ?>
            <div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-4 mb-4 animate__animated animate__headShake">
                <div class="flex">
                    <div class="flex-shrink-0">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                        </svg>
                    </div>
                    <div class="ml-3">
                        <ul class="list-disc list-inside">
                            <?php foreach ($errors as $error): ?>
                                <li><?php echo $error; ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                </div>
            </div>
        <?php endif; ?>
        
        <div class="bg-white p-8 rounded-lg shadow-md border border-gray-200">
            <form class="space-y-6" action="admin_login.php" method="POST" id="adminLoginForm">
                <div>
                    <label for="username" class="block text-sm font-medium text-gray-700 mb-2">
                        Username or Email
                    </label>
                    <div class="relative rounded-md shadow-sm">
                        <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                            <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" />
                            </svg>
                        </div>
                        <input id="username" name="username" type="text" required 
                            value="<?php echo isset($_POST['username']) ? htmlspecialchars($_POST['username']) : ''; ?>"
                            class="focus:ring-green-500 focus:border-green-500 block w-full pl-10 pr-3 py-3 border border-gray-300 rounded-md transition-colors duration-300"
                            placeholder="Enter admin username or email">
                    </div>
                </div>
                
                <div>
                    <label for="password" class="block text-sm font-medium text-gray-700 mb-2">
                        Password
                    </label>
                    <div class="relative rounded-md shadow-sm">
                        <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                            <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z" />
                            </svg>
                        </div>
                        <input id="password" name="password" type="password" required
                            class="focus:ring-green-500 focus:border-green-500 block w-full pl-10 pr-10 py-3 border border-gray-300 rounded-md transition-colors duration-300"
                            placeholder="Enter admin password">
                        <div class="absolute inset-y-0 right-0 pr-3 flex items-center">
                            <button type="button" onclick="togglePassword()" class="text-gray-400 hover:text-gray-600">
                                <svg id="eyeIcon" xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243M9.878 9.878l4.242 4.242M9.878 9.878L3 3m6.878 6.878L21 21" />
                                </svg>
                            </button>
                        </div>
                    </div>
                </div>
                
                <div>
                    <button type="submit" id="submitBtn" class="group relative w-full flex justify-center py-3 px-4 border border-transparent text-sm font-medium rounded-md text-white bg-green-600 hover:bg-green-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-green-500 transition-all duration-300 transform hover:scale-[1.02]">
                        <span class="absolute left-0 inset-y-0 flex items-center pl-3">
                            <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 text-green-500 group-hover:text-green-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z" />
                            </svg>
                        </span>
                        <span id="btnText">Sign in to Admin Panel</span>
                    </button>
                </div>
            </form>
            
            <div class="mt-6 text-center">
                <a href="index.php" class="text-sm text-green-600 hover:text-green-800 flex items-center justify-center transition-colors duration-300">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 mr-1" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18" />
                    </svg>
                    Return to Home Page
                </a>
            </div>
        </div>
        
        <!-- Admin Account Setup Notice -->
        <div class="bg-green-50 border border-green-200 rounded-lg p-4">
            <div class="flex">
                <div class="flex-shrink-0">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 text-green-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                    </svg>
                </div>
                <div class="ml-3">
                    <h3 class="text-sm font-medium text-green-800">First Time Setup?</h3>
                    <div class="mt-2 text-sm text-green-700">
                        <p>If you don't have an admin account yet, you'll need to create one in your database. 
                        <a href="#" onclick="showSetupInstructions()" class="font-medium underline hover:text-green-900">
                            Click here for setup instructions
                        </a></p>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="mt-6 text-center text-xs text-gray-500">
            <p class="flex items-center justify-center">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 mr-1" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z" />
                </svg>
                This is a restricted area. Unauthorized access is prohibited.
            </p>
        </div>
    </div>
</div>

<!-- Setup Instructions Modal -->
<div id="setupModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 hidden items-center justify-center z-50">
    <div class="bg-white rounded-lg p-6 max-w-2xl w-full mx-4 max-h-96 overflow-y-auto">
        <div class="flex justify-between items-center mb-4">
            <h3 class="text-lg font-medium text-gray-900">Admin Account Setup Instructions</h3>
            <button onclick="hideSetupInstructions()" class="text-gray-400 hover:text-gray-600">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                </svg>
            </button>
        </div>
        <div class="text-sm text-gray-700">
            <p class="mb-4">To create your first admin account, execute this SQL query in your database:</p>
            <div class="bg-gray-100 p-4 rounded-lg font-mono text-xs overflow-x-auto">
                <code>
INSERT INTO users (username, email, password, role, created_at)<br>
VALUES ('admin', 'admin@yourdomain.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'admin', NOW());
                </code>
            </div>
            <div class="mt-4 space-y-2">
                <p><strong>Default Login Credentials:</strong></p>
                <ul class="list-disc list-inside ml-4">
                    <li>Username: <code class="bg-gray-100 px-1 rounded">admin</code></li>
                    <li>Password: <code class="bg-gray-100 px-1 rounded">password</code></li>
                </ul>
                <p class="text-amber-600 font-medium">⚠️ Remember to change the password after your first login!</p>
            </div>
        </div>
        <div class="mt-6 flex justify-end">
            <button onclick="hideSetupInstructions()" class="bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded-md">
                Got it!
            </button>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const form = document.getElementById('adminLoginForm');
    const submitButton = document.getElementById('submitBtn');
    const btnText = document.getElementById('btnText');
    
    form.addEventListener('submit', function() {
        submitButton.disabled = true;
        btnText.innerHTML = `
            <svg class="animate-spin -ml-1 mr-2 h-4 w-4 text-white inline-block" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
            </svg>
            Authenticating...
        `;
    });
    
    // Auto-focus username field
    document.getElementById('username').focus();
});

function togglePassword() {
    const passwordField = document.getElementById('password');
    const eyeIcon = document.getElementById('eyeIcon');
    
    if (passwordField.type === 'password') {
        passwordField.type = 'text';
        eyeIcon.innerHTML = `
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243M9.878 9.878l4.242 4.242M9.878 9.878L3 3m6.878 6.878L21 21" />
        `;
    } else {
        passwordField.type = 'password';
        eyeIcon.innerHTML = `
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
        `;
    }
}

function showSetupInstructions() {
    document.getElementById('setupModal').classList.remove('hidden');
    document.getElementById('setupModal').classList.add('flex');
}

function hideSetupInstructions() {
    document.getElementById('setupModal').classList.add('hidden');
    document.getElementById('setupModal').classList.remove('flex');
}

// Close modal when clicking outside
document.getElementById('setupModal').addEventListener('click', function(e) {
    if (e.target === this) {
        hideSetupInstructions();
    }
});
</script>

<?php include 'includes/footer.php'; ?>