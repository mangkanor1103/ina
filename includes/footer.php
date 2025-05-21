</main>
    
    <footer class="bg-green-800 text-white py-10 mt-auto">
        <div class="container mx-auto px-4">
            <div class="grid grid-cols-1 md:grid-cols-3 gap-8">
                <div>
                    <h3 class="text-xl font-bold mb-4">Learning Management System</h3>
                    <p class="text-green-200">Empowering education through technology, making learning more accessible, interactive, and effective.</p>
                </div>
                
                <div>
                    <h3 class="text-xl font-bold mb-4">Quick Links</h3>
                    <ul class="space-y-2">
                        <li><a href="index.php" class="text-green-200 hover:text-white transition-colors duration-300">Home</a></li>
                        <?php if (is_logged_in()): ?>
                            <?php if (is_teacher() || is_admin()): ?>
                                <li><a href="classrooms.php" class="text-green-200 hover:text-white transition-colors duration-300">My Classrooms</a></li>
                                <li><a href="lessons.php" class="text-green-200 hover:text-white transition-colors duration-300">Lessons</a></li>
                            <?php else: ?>
                                <li><a href="my_classes.php" class="text-green-200 hover:text-white transition-colors duration-300">My Classes</a></li>
                            <?php endif; ?>
                            <li><a href="profile.php" class="text-green-200 hover:text-white transition-colors duration-300">My Profile</a></li>
                            <li><a href="logout.php" class="text-green-200 hover:text-white transition-colors duration-300">Logout</a></li>
                        <?php else: ?>
                            <li><a href="login.php" class="text-green-200 hover:text-white transition-colors duration-300">Login</a></li>
                            <li><a href="register.php" class="text-green-200 hover:text-white transition-colors duration-300">Register</a></li>
                        <?php endif; ?>
                    </ul>
                </div>
                
                <div>
                    <h3 class="text-xl font-bold mb-4">Contact</h3>
                    <p class="text-green-200 mb-2">If you need assistance or have any questions, feel free to contact our support team.</p>
                    <a href="mailto:support@lms.com" class="text-white hover:text-green-200 transition-colors duration-300">support@lms.com</a>
                </div>
            </div>
            
            <div class="border-t border-green-700 mt-8 pt-8 text-center text-green-200">
                <p>&copy; <?php echo date('Y'); ?> Learning Management System. All rights reserved.</p>
            </div>
        </div>
    </footer>
    
    <!-- Load app.js for global functions -->
    <script src="assets/js/app.js"></script>
    
    <!-- Page transition effect -->
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Fade in body content
            document.body.classList.add('fade-in');
            
            // Add transition effect when leaving page
            document.addEventListener('click', function(e) {
                const link = e.target.closest('a');
                if (link && link.href && link.href.indexOf(window.location.hostname) !== -1 && !link.target && !e.ctrlKey && !e.metaKey) {
                    e.preventDefault();
                    document.body.classList.add('animate__animated', 'animate__fadeOut', 'animate__faster');
                    setTimeout(function() {
                        window.location.href = link.href;
                    }, 300);
                }
            });
        });
    </script>
</body>
</html>