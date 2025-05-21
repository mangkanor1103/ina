<?php
include 'includes/header.php'; ?>

<section class="relative fade-in">
    <!-- Hero Section -->
    <div class="bg-primary-gradient text-white py-20 rounded-lg shadow-xl">
        <div class="container mx-auto px-6 text-center">
            <h1 class="text-5xl font-bold mb-4 animate__animated animate__fadeInDown">Welcome to Learning Management System</h1>
            <p class="text-xl mb-8 animate__animated animate__fadeIn animate__delay-1s">Empowering education through innovative technology</p>
            
            <?php if (!is_logged_in()): ?>
                <div class="flex justify-center space-x-4 animate__animated animate__fadeInUp animate__delay-1s">
                    <a href="login.php" class="bg-white text-green-600 hover:bg-gray-100 font-bold px-6 py-3 rounded-lg transition-all duration-300 hover:shadow-lg">
                        Login
                    </a>
                    <a href="register.php" class="bg-green-800 hover:bg-green-900 font-bold px-6 py-3 rounded-lg transition-all duration-300 hover:shadow-lg">
                        Register
                    </a>
                </div>
            <?php else: ?>
                <div class="mt-8 animate__animated animate__fadeInUp animate__delay-1s">
                    <?php if (has_role('teacher')): ?>
                        <a href="classrooms.php" class="bg-white text-green-600 hover:bg-gray-100 font-bold px-6 py-3 rounded-lg transition-all duration-300 hover:shadow-lg">
                            Manage Your Classrooms
                        </a>
                    <?php else: ?>
                        <a href="my_classes.php" class="bg-white text-green-600 hover:bg-gray-100 font-bold px-6 py-3 rounded-lg transition-all duration-300 hover:shadow-lg">
                            Go to My Classes
                        </a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</section>

<!-- Features Section -->
<section class="py-16 slide-up" style="animation-delay: 0.3s;">
    <div class="container mx-auto px-4">
        <h2 class="text-3xl font-bold text-center mb-12 text-green-800">Key Features</h2>
        
        <div class="grid grid-cols-1 md:grid-cols-3 gap-8">
            <!-- Feature 1 -->
            <div class="feature-card bg-white p-6 rounded-lg shadow-md hover-scale">
                <div class="flex justify-center mb-4">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-12 w-12 text-green-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253" />
                    </svg>
                </div>
                <h3 class="text-xl font-semibold mb-2 text-center text-green-700">Easy Lesson Management</h3>
                <p class="text-gray-600">Teachers can easily upload, organize, and distribute learning materials to students in their classrooms.</p>
            </div>
            
            <!-- Feature 2 -->
            <div class="feature-card bg-white p-6 rounded-lg shadow-md hover-scale">
                <div class="flex justify-center mb-4">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-12 w-12 text-green-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                    </svg>
                </div>
                <h3 class="text-xl font-semibold mb-2 text-center text-green-700">Assignment Submissions</h3>
                <p class="text-gray-600">Students can submit their work directly through the platform, making it easy to track assignments and deadlines.</p>
            </div>
            
            <!-- Feature 3 -->
            <div class="feature-card bg-white p-6 rounded-lg shadow-md hover-scale">
                <div class="flex justify-center mb-4">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-12 w-12 text-green-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z" />
                    </svg>
                </div>
                <h3 class="text-xl font-semibold mb-2 text-center text-green-700">Grading System</h3>
                <p class="text-gray-600">Teachers can provide grades and feedback on student submissions, helping track academic progress.</p>
            </div>
        </div>
    </div>
</section>

<!-- How It Works Section -->
<section class="py-16 bg-green-50 slide-up" style="animation-delay: 0.6s;">
    <div class="container mx-auto px-4">
        <h2 class="text-3xl font-bold text-center mb-12 text-green-800">How It Works</h2>
        
        <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
            <!-- Step 1 -->
            <div class="flex flex-col items-center hover-scale">
                <div class="bg-green-500 text-white rounded-full w-16 h-16 flex items-center justify-center text-xl font-bold mb-4 shadow-md">1</div>
                <h3 class="text-xl font-semibold mb-2 text-green-700">Create a Classroom</h3>
                <p class="text-center text-gray-600">Teachers can create virtual classrooms for their subjects and invite students.</p>
            </div>
            
            <!-- Step 2 -->
            <div class="flex flex-col items-center hover-scale">
                <div class="bg-green-500 text-white rounded-full w-16 h-16 flex items-center justify-center text-xl font-bold mb-4 shadow-md">2</div>
                <h3 class="text-xl font-semibold mb-2 text-green-700">Upload Learning Materials</h3>
                <p class="text-center text-gray-600">Share lessons, notes, assignments, and other resources with students.</p>
            </div>
            
            <!-- Step 3 -->
            <div class="flex flex-col items-center hover-scale">
                <div class="bg-green-500 text-white rounded-full w-16 h-16 flex items-center justify-center text-xl font-bold mb-4 shadow-md">3</div>
                <h3 class="text-xl font-semibold mb-2 text-green-700">Track Progress</h3>
                <p class="text-center text-gray-600">View student submissions, provide feedback, and monitor learning progress.</p>
            </div>
        </div>
    </div>
</section>

<!-- Call to Action -->
<section class="py-16 slide-up" style="animation-delay: 0.9s;">
    <div class="container mx-auto px-4 text-center">
        <h2 class="text-3xl font-bold mb-6 text-green-800">Ready to Get Started?</h2>
        <p class="text-lg text-gray-600 mb-8 max-w-2xl mx-auto">Join our learning community today and experience a better way to teach and learn.</p>
        
        <a href="<?php echo is_logged_in() ? (has_role('teacher') ? 'classrooms.php' : 'my_classes.php') : 'register.php'; ?>" 
           class="bg-green-600 hover:bg-green-700 text-white font-bold px-8 py-4 rounded-lg text-lg transition-all duration-300 hover:shadow-lg inline-block">
           <?php echo is_logged_in() ? 'Go to Dashboard' : 'Create Free Account'; ?>
        </a>
    </div>
</section>

<?php include 'includes/footer.php'; ?>