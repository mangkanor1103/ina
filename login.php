<?php
include 'includes/header.php';

// Redirect if already logged in
if (is_logged_in()) {
    redirect("index.php");
}

// Process login form
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $username = clean_input($_POST['username']);
    $password = $_POST['password'];
    
    // Validate input
    $errors = [];
    
    if (empty($username)) {
        $errors[] = "Username is required";
    }
    
    if (empty($password)) {
        $errors[] = "Password is required";
    }
    
    // If no validation errors, attempt login
    if (empty($errors)) {
        $stmt = $conn->prepare("SELECT id, username, password, role FROM users WHERE username = ? OR email = ?");
        $stmt->bind_param("ss", $username, $username);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows === 1) {
            $user = $result->fetch_assoc();
            
            // Verify password
            if (password_verify($password, $user['password'])) {
                // Set session variables
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['username'] = $user['username'];
                $_SESSION['role'] = $user['role'];
                
                // Show a welcome back animation before redirecting
                echo "<script>
                    Swal.fire({
                        title: 'Welcome back!',
                        text: 'You have successfully logged in.',
                        icon: 'success',
                        confirmButtonColor: '#16a34a',
                        timer: 1500,
                        timerProgressBar: true,
                        showConfirmButton: false,
                        showClass: {
                            popup: 'animate__animated animate__fadeIn animate__faster'
                        },
                        hideClass: {
                            popup: 'animate__animated animate__fadeOut animate__faster'
                        }
                    }).then(() => {
                        window.location.href = '".($user['role'] === 'teacher' ? "classrooms.php" : "my_classes.php")."';
                    });
                </script>";
                exit;
            } else {
                $errors[] = "Invalid username or password";
            }
        } else {
            $errors[] = "Invalid username or password";
        }
    }
}
?>

<div class="max-w-md mx-auto bg-white p-8 rounded-lg shadow-md hover-scale animate__animated animate__fadeIn">
    <div class="text-center mb-8">
        <svg xmlns="http://www.w3.org/2000/svg" class="h-16 w-16 mx-auto text-green-600 mb-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z" />
        </svg>
        <h2 class="text-3xl font-bold mb-2 text-green-800">Welcome Back</h2>
        <p class="text-gray-600">Sign in to access your account</p>
    </div>
    
    <?php if (!empty($errors)): ?>
        <div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-4 mb-6 animate__animated animate__headShake">
            <ul class="list-disc list-inside">
                <?php foreach ($errors as $error): ?>
                    <li><?php echo $error; ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>
    
    <form method="POST" action="login.php" id="loginForm">
        <div class="mb-5">
            <label for="username" class="block text-gray-700 font-medium mb-2">Username or Email</label>
            <div class="relative">
                <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" />
                    </svg>
                </div>
                <input type="text" id="username" name="username" value="<?php echo isset($username) ? htmlspecialchars($username) : ''; ?>" 
                       class="w-full pl-10 px-4 py-3 border rounded-lg focus:outline-none focus:ring-2 focus:ring-green-500 transition-all duration-300" 
                       autocomplete="username" autofocus>
            </div>
        </div>
        
        <div class="mb-6">
            <div class="flex justify-between items-center mb-2">
                <label for="password" class="block text-gray-700 font-medium">Password</label>
                <a href="#" class="text-sm text-green-600 hover:text-green-800 transition-colors duration-300">Forgot Password?</a>
            </div>
            <div class="relative">
                <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z" />
                    </svg>
                </div>
                <input type="password" id="password" name="password" 
                       class="w-full pl-10 px-4 py-3 border rounded-lg focus:outline-none focus:ring-2 focus:ring-green-500 transition-all duration-300"
                       autocomplete="current-password">
                <div class="absolute inset-y-0 right-0 pr-3 flex items-center">
                    <button type="button" id="togglePassword" class="text-gray-400 hover:text-gray-600 focus:outline-none">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" id="eyeIcon">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
                        </svg>
                    </button>
                </div>
            </div>
        </div>
        
        <button type="submit" class="w-full bg-green-600 text-white font-medium py-3 px-4 rounded-lg hover:bg-green-700 transition-all duration-300 transform hover:scale-[1.02] flex justify-center items-center">
            <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 16l-4-4m0 0l4-4m-4 4h14m-5 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h7a3 3 0 013 3v1" />
            </svg>
            Login
        </button>
    </form>
    
    <div class="mt-8 pt-6 border-t border-gray-200">
        <p class="text-center text-gray-600">
            Don't have an account? 
            <a href="register.php" class="text-green-600 hover:text-green-800 font-medium transition-colors duration-300 hover:underline">
                Register here
            </a>
        </p>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Toggle password visibility
    const togglePassword = document.getElementById('togglePassword');
    const password = document.getElementById('password');
    const eyeIcon = document.getElementById('eyeIcon');
    
    togglePassword.addEventListener('click', function() {
        // Toggle type attribute
        const type = password.getAttribute('type') === 'password' ? 'text' : 'password';
        password.setAttribute('type', type);
        
        // Toggle eye icon
        if (type === 'text') {
            // Show "eye-off" icon when password is visible
            eyeIcon.innerHTML = `
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243M9.878 9.878l4.242 4.242M9.88 9.88l-3.29-3.29m7.532 7.532l3.29 3.29M3 3l3.59 3.59m0 0A9.953 9.953 0 0112 5c4.478 0 8.268 2.943 9.543 7a10.025 10.025 0 01-4.132 5.411m0 0L21 21" />
            `;
        } else {
            // Show regular "eye" icon when password is hidden
            eyeIcon.innerHTML = `
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
            `;
        }
    });
    
    // Add subtle animation to the form on focus
    const inputs = document.querySelectorAll('input');
    inputs.forEach(input => {
        input.addEventListener('focus', function() {
            document.querySelector('.max-w-md').classList.add('shadow-lg');
        });
        
        input.addEventListener('blur', function() {
            if (!document.querySelector('input:focus')) {
                document.querySelector('.max-w-md').classList.remove('shadow-lg');
            }
        });
    });
});
</script>

<?php include 'includes/footer.php'; ?>