/**
 * Confirmation dialog using SweetAlert2
 */
function confirmDelete(url, itemName = 'item') {
    Swal.fire({
        title: 'Are you sure?',
        text: `This will permanently delete this ${itemName}. This action cannot be undone!`,
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#16a34a',
        cancelButtonColor: '#dc2626',
        confirmButtonText: 'Yes, delete it!',
        cancelButtonText: 'Cancel',
        showClass: {
            popup: 'animate__animated animate__fadeIn animate__faster'
        },
        hideClass: {
            popup: 'animate__animated animate__fadeOut animate__faster'
        }
    }).then((result) => {
        if (result.isConfirmed) {
            window.location.href = url;
        }
    });
}

/**
 * Form submission confirmation
 */
function confirmFormSubmit(formId, message = 'Are you sure you want to submit this form?') {
    document.getElementById(formId).addEventListener('submit', function(e) {
        e.preventDefault();
        
        Swal.fire({
            title: 'Confirm Submission',
            text: message,
            icon: 'question',
            showCancelButton: true,
            confirmButtonColor: '#16a34a',
            cancelButtonColor: '#6b7280',
            confirmButtonText: 'Yes, submit',
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
}

/**
 * Success message with animation
 */
function showSuccess(message) {
    Swal.fire({
        title: 'Success!',
        text: message,
        icon: 'success',
        confirmButtonColor: '#16a34a',
        timer: 3000,
        timerProgressBar: true,
        showClass: {
            popup: 'animate__animated animate__fadeIn animate__faster'
        },
        hideClass: {
            popup: 'animate__animated animate__fadeOut animate__faster'
        }
    });
}

/**
 * Error message with animation
 */
function showError(message) {
    Swal.fire({
        title: 'Error!',
        text: message,
        icon: 'error',
        confirmButtonColor: '#16a34a',
        showClass: {
            popup: 'animate__animated animate__fadeIn animate__faster' 
        },
        hideClass: {
            popup: 'animate__animated animate__fadeOut animate__faster'
        }
    });
}