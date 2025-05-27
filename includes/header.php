<?php
require_once 'config/database.php';
require_once 'includes/functions.php';
session_start_safe();

// Get current page
$current_page = basename($_SERVER['PHP_SELF']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Learning Management System</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <!-- SweetAlert2 for better notifications -->
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <!-- Animation library -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/animate.css/4.1.1/animate.min.css">
    <style>
        /* Green Theme Custom Colors */
        :root {
            --primary-light: #4ade80;  /* Light green */
            --primary: #16a34a;        /* Main green */
            --primary-dark: #166534;   /* Dark green */
            --secondary-light: #d1fae5; /* Very light green */
            --secondary: #059669;      /* Teal green */
            --accent: #047857;         /* Dark teal */
        }
        
        /* Custom Classes for Green Theme */
        .bg-primary-gradient {
            background-image: linear-gradient(to right, #059669, #047857);
        }
        
        /* Animations and Transitions */
        .hover-scale {
            transition: transform 0.3s ease;
        }
        .hover-scale:hover {
            transform: scale(1.03);
        }
        
        .fade-in {
            animation: fadeIn 0.8s ease-in;
        }
        
        .slide-up {
            animation: slideUp 0.5s ease-out;
        }
        
        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }
        
        @keyframes slideUp {
            from { transform: translateY(20px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }
        
        /* Card hover effects */
        .feature-card {
            transition: all 0.3s ease;
            border-top: 3px solid transparent;
        }
        
        .feature-card:hover {
            transform: translateY(-5px);
            border-top: 3px solid var(--primary);
            box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -2px rgba(0, 0, 0, 0.05);
        }
        
        /* Navigation animations */
        .nav-link {
            position: relative;
        }
        
        .nav-link::after {
            content: '';
            position: absolute;
            width: 0;
            height: 2px;
            bottom: -2px;
            left: 0;
            background-color: var(--primary);
            transition: width 0.3s ease;
        }
        
        .nav-link:hover::after {
            width: 100%;
        }
    </style>
</head>
<body class="bg-gray-50 min-h-screen flex flex-col">
    <!-- Flash message handler -->
    <?php if (isset($_SESSION['flash_messages']) && !empty($_SESSION['flash_messages'])): ?>
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        <?php foreach ($_SESSION['flash_messages'] as $type => $message): ?>
            Swal.fire({
                title: '<?php echo ucfirst($type); ?>',
                text: '<?php echo $message; ?>',
                icon: '<?php echo ($type == "error") ? "error" : (($type == "warning") ? "warning" : "success"); ?>',
                confirmButtonColor: '#16a34a', // Green button for all alerts
                timer: <?php echo ($type == "error") ? "0" : "3000"; ?>,
                timerProgressBar: true,
                showClass: {
                    popup: 'animate__animated animate__fadeIn animate__faster'
                },
                hideClass: {
                    popup: 'animate__animated animate__fadeOut animate__faster'
                }
            });
        <?php endforeach; ?>
        <?php unset($_SESSION['flash_messages']); ?>
    });
    </script>
    <?php endif; ?>

    <header class="bg-primary-gradient shadow-md">
        <div class="container mx-auto px-4 py-3">
            <div class="flex justify-between items-center">
                <a href="index.php" class="text-white font-bold text-xl flex items-center hover-scale">
                    <!-- Logo Image -->
                    <img src="logo.png" alt="LearnHub Logo" class="h-10 w-10 mr-3 rounded-lg shadow-sm">
                    LEARNHUB
                </a>
                
                <nav>
                    <ul class="flex space-x-4 text-white">
                        <li><a href="index.php" class="nav-link px-3 py-2 rounded-md <?php echo $current_page === 'index.php' ? 'bg-primary-dark' : ''; ?>">Home</a></li>
                        
                        <?php if (is_logged_in()): ?>
                            <?php if (has_role('teacher') || has_role('admin')): ?>
                                <li><a href="classrooms.php" class="nav-link px-3 py-2 rounded-md <?php echo $current_page === 'classrooms.php' ? 'bg-primary-dark' : ''; ?>">Classrooms</a></li>
                                <li><a href="lessons.php" class="nav-link px-3 py-2 rounded-md <?php echo $current_page === 'lessons.php' ? 'bg-primary-dark' : ''; ?>">Lessons</a></li>
                            <?php else: ?>
                                <li><a href="my_classes.php" class="nav-link px-3 py-2 rounded-md <?php echo $current_page === 'my_classes.php' ? 'bg-primary-dark' : ''; ?>">My Classes</a></li>
                                <li><a href="my_submissions.php" class="nav-link px-3 py-2 rounded-md <?php echo $current_page === 'my_submissions.php' ? 'bg-primary-dark' : ''; ?>">My Submissions</a></li>
                            <?php endif; ?>
                            
                            <li><a href="profile.php" class="nav-link px-3 py-2 rounded-md <?php echo $current_page === 'profile.php' ? 'bg-primary-dark' : ''; ?>">Profile</a></li>
                            <li><a href="logout.php" class="bg-red-500 hover:bg-red-600 px-3 py-2 rounded-md">Logout</a></li>
                        <?php else: ?>
                            <li><a href="login.php" class="nav-link px-3 py-2 rounded-md <?php echo $current_page === 'login.php' ? 'bg-primary-dark' : ''; ?>">Login</a></li>
                            <li><a href="register.php" class="nav-link px-3 py-2 rounded-md <?php echo $current_page === 'register.php' ? 'bg-primary-dark' : ''; ?>">Register</a></li>
                        <?php endif; ?>
                    </ul>
                </nav>
            </div>
        </div>
    </header>
    
    <main class="container mx-auto px-4 py-6">
        <?php 
        $flash = get_flash_message();
        if ($flash): 
            $color_class = $flash['type'] === 'success' ? 'bg-green-100 border-green-500 text-green-700' : 'bg-red-100 border-red-500 text-red-700';
        ?>
            <div class="<?php echo $color_class; ?> p-4 mb-6 border-l-4 rounded">
                <?php echo $flash['message']; ?>
            </div>
        <?php endif; ?>