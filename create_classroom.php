<?php
include 'includes/header.php';

// Check if user is logged in and is a teacher
if (!is_logged_in() || !has_role('teacher')) {
    set_flash_message("error", "You don't have permission to access this page.");
    redirect("index.php");
}

// Process form submission
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $name = clean_input($_POST['name']);
    $description = clean_input($_POST['description']);
    $teacher_id = $_SESSION['user_id'];
    
    // Validate input
    $errors = [];
    
    if (empty($name)) {
        $errors[] = "Classroom name is required";
    }
    
    // If no errors, create the classroom
    if (empty($errors)) {
        $stmt = $conn->prepare("INSERT INTO classrooms (name, description, teacher_id, created_at) VALUES (?, ?, ?, NOW())");
        $stmt->bind_param("ssi", $name, $description, $teacher_id);
        
        if ($stmt->execute()) {
            $classroom_id = $conn->insert_id;
            
            // Check if activity_log table exists and add entry
            $activity_table_exists = $conn->query("SHOW TABLES LIKE 'activity_log'")->num_rows > 0;
            
            if ($activity_table_exists) {
                $activity = "Created new classroom: " . $name;
                $log_stmt = $conn->prepare("
                    INSERT INTO activity_log (classroom_id, user_id, activity_type, description, created_at) 
                    VALUES (?, ?, 'classroom_created', ?, NOW())
                ");
                $log_stmt->bind_param("iis", $classroom_id, $teacher_id, $activity);
                $log_stmt->execute();
            }
            
            // Show success message with SweetAlert
            echo "<script>
                document.addEventListener('DOMContentLoaded', function() {
                    Swal.fire({
                        title: 'Success!',
                        text: 'Your classroom has been created successfully.',
                        icon: 'success',
                        confirmButtonColor: '#16a34a',
                        timer: 2000,
                        timerProgressBar: true,
                        showClass: {
                            popup: 'animate__animated animate__fadeIn animate__faster'
                        },
                        hideClass: {
                            popup: 'animate__animated animate__fadeOut animate__faster'
                        }
                    }).then(() => {
                        window.location.href = 'view_classroom.php?id=" . $classroom_id . "';
                    });
                });
            </script>";
            exit;
            
        } else {
            $errors[] = "Failed to create classroom. Please try again.";
        }
    }
}
?>

<div class="container mx-auto px-4 py-8 fade-in">
    <div class="mb-6 animate__animated animate__fadeInLeft">
        <a href="classrooms.php" class="text-green-600 hover:text-green-800 hover:underline flex items-center transition-colors duration-300">
            <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 mr-1" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18" />
            </svg>
            Back to Classrooms
        </a>
    </div>

    <div class="max-w-2xl mx-auto bg-white rounded-lg shadow-md overflow-hidden hover-scale animate__animated animate__fadeInUp">
        <div class="bg-green-600 p-6 text-white">
            <h1 class="text-2xl font-bold flex items-center">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4" />
                </svg>
                Create New Classroom
            </h1>
            <p class="text-green-100 mt-2">Set up a virtual space for your students to learn and collaborate.</p>
        </div>
        
        <div class="p-6">
            <?php if (!empty($errors)): ?>
                <div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-4 mb-6 animate__animated animate__headShake">
                    <ul class="list-disc list-inside">
                        <?php foreach ($errors as $error): ?>
                            <li><?php echo $error; ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>
            
            <form method="POST" action="create_classroom.php" id="createClassroomForm">
                <div class="mb-5">
                    <label for="name" class="block text-gray-700 font-medium mb-2">
                        Classroom Name <span class="text-red-500">*</span>
                    </label>
                    <div class="relative">
                        <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                            <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4" />
                            </svg>
                        </div>
                        <input type="text" id="name" name="name" value="<?php echo isset($name) ? htmlspecialchars($name) : ''; ?>" 
                               class="w-full pl-10 px-4 py-3 border rounded-lg focus:outline-none focus:ring-2 focus:ring-green-500 transition-all duration-300" 
                               placeholder="e.g. Biology 101, Mathematics Grade 8" required autofocus>
                    </div>
                    <p class="text-sm text-gray-500 mt-1">Choose a clear, descriptive name for your classroom.</p>
                </div>
                
                <div class="mb-6">
                    <label for="description" class="block text-gray-700 font-medium mb-2">
                        Description
                    </label>
                    <textarea id="description" name="description" rows="4" 
                              class="w-full px-4 py-3 border rounded-lg focus:outline-none focus:ring-2 focus:ring-green-500 transition-all duration-300"
                              placeholder="Describe the purpose and scope of this classroom..."><?php echo isset($description) ? htmlspecialchars($description) : ''; ?></textarea>
                    <p class="text-sm text-gray-500 mt-1">Provide details about your course, class objectives, or other relevant information.</p>
                </div>
                
                <div class="bg-green-50 p-4 rounded-lg mb-6 border border-green-100">
                    <h3 class="font-medium text-green-800 mb-2 flex items-center">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 mr-1" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                        </svg>
                        What happens next?
                    </h3>
                    <p class="text-green-700 text-sm">
                        After creating your classroom, you'll be able to add lessons and invite students to join.
                        You can manage all aspects of your classroom including student enrollment, lessons, and assignments.
                    </p>
                </div>
                
                <div class="flex justify-between">
                    <a href="classrooms.php" class="bg-gray-100 hover:bg-gray-200 text-gray-700 px-5 py-2 rounded-lg transition-all duration-300">
                        Cancel
                    </a>
                    <button type="submit" class="bg-green-600 hover:bg-green-700 text-white px-6 py-2 rounded-lg transition-all duration-300 transform hover:scale-[1.02] flex items-center">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6" />
                        </svg>
                        Create Classroom
                    </button>
                </div>
            </form>
        </div>
    </div>
    
    <!-- Tips Section -->
    <div class="max-w-2xl mx-auto mt-8 bg-white rounded-lg shadow-md p-6 animate__animated animate__fadeIn" style="animation-delay: 0.3s;">
        <h2 class="text-xl font-bold mb-4 text-green-800 flex items-center">
            <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.663 17h4.673M12 3v1m6.364 1.636l-.707.707M21 12h-1M4 12H3m3.343-5.657l-.707-.707m2.828 9.9a5 5 0 117.072 0l-.548.547A3.374 3.374 0 0014 18.469V19a2 2 0 11-4 0v-.531c0-.895-.356-1.754-.988-2.386l-.548-.547z" />
            </svg>
            Tips for Effective Classroom Setup
        </h2>
        <div class="space-y-3">
            <div class="flex items-start">
                <div class="bg-green-100 text-green-600 rounded-full h-6 w-6 flex items-center justify-center mr-3 flex-shrink-0 mt-0.5">1</div>
                <div>
                    <h3 class="font-medium text-gray-800">Be descriptive with your classroom name</h3>
                    <p class="text-gray-600 text-sm">Include the subject, grade level, or class period to help students identify the right classroom.</p>
                </div>
            </div>
            <div class="flex items-start">
                <div class="bg-green-100 text-green-600 rounded-full h-6 w-6 flex items-center justify-center mr-3 flex-shrink-0 mt-0.5">2</div>
                <div>
                    <h3 class="font-medium text-gray-800">Add a detailed description</h3>
                    <p class="text-gray-600 text-sm">Include course objectives, meeting times, or other important information that helps orient students.</p>
                </div>
            </div>
            <div class="flex items-start">
                <div class="bg-green-100 text-green-600 rounded-full h-6 w-6 flex items-center justify-center mr-3 flex-shrink-0 mt-0.5">3</div>
                <div>
                    <h3 class="font-medium text-gray-800">Plan your lesson structure</h3>
                    <p class="text-gray-600 text-sm">Before adding content, think about how you want to organize topics and materials for easy navigation.</p>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Add confirmation dialog to form submission
    const form = document.getElementById('createClassroomForm');
    
    form.addEventListener('submit', function(e) {
        e.preventDefault();
        
        // Check if form is valid (HTML5 validation)
        if (!this.checkValidity()) {
            this.reportValidity();
            return;
        }
        
        // Get the classroom name
        const className = document.getElementById('name').value;
        
        Swal.fire({
            title: 'Create Classroom?',
            text: `You're about to create the "${className}" classroom. Continue?`,
            icon: 'question',
            showCancelButton: true,
            confirmButtonColor: '#16a34a',
            cancelButtonColor: '#6b7280',
            confirmButtonText: 'Yes, create it!',
            cancelButtonText: 'Cancel',
            showClass: {
                popup: 'animate__animated animate__fadeIn animate__faster'
            },
            hideClass: {
                popup: 'animate__animated animate__fadeOut animate__faster'
            }
        }).then((result) => {
            if (result.isConfirmed) {
                this.submit();
            }
        });
    });
    
    // Add subtle animation to the form on focus
    const inputs = document.querySelectorAll('input, textarea');
    inputs.forEach(input => {
        input.addEventListener('focus', function() {
            this.closest('.hover-scale').style.transform = 'scale(1.01)';
            this.closest('.hover-scale').style.boxShadow = '0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -2px rgba(0, 0, 0, 0.05)';
        });
        
        input.addEventListener('blur', function() {
            if (!document.querySelector('input:focus, textarea:focus')) {
                this.closest('.hover-scale').style.transform = 'scale(1)';
                this.closest('.hover-scale').style.boxShadow = '0 1px 3px 0 rgba(0, 0, 0, 0.1), 0 1px 2px 0 rgba(0, 0, 0, 0.06)';
            }
        });
    });
});
</script>

<?php include 'includes/footer.php'; ?>