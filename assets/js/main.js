// Main JavaScript file

// Toast Notification System
function showToast(message, type = 'success', duration = 5000) {
    // Create toast container if it doesn't exist
    let container = document.getElementById('toast-container');
    if (!container) {
        container = document.createElement('div');
        container.id = 'toast-container';
        container.className = 'toast-container';
        document.body.appendChild(container);
    }
    
    // Create toast element
    const toast = document.createElement('div');
    toast.className = 'toast toast-' + type;
    
    // Set icon based on type
    let icon = 'fa-check-circle';
    if (type === 'error') {
        icon = 'fa-exclamation-circle';
    } else if (type === 'warning') {
        icon = 'fa-exclamation-triangle';
    } else if (type === 'info') {
        icon = 'fa-info-circle';
    }
    
    toast.innerHTML = `
        <i class="fas ${icon} toast-icon"></i>
        <div class="toast-content">${message}</div>
        <button class="toast-close" onclick="this.parentElement.remove()">
            <i class="fas fa-times"></i>
        </button>
    `;
    
    // Add to container
    container.appendChild(toast);
    
    // Auto remove after duration
    setTimeout(() => {
        toast.classList.add('slide-out');
        setTimeout(() => {
            if (toast.parentElement) {
                toast.remove();
            }
        }, 300);
    }, duration);
    
    return toast;
}

// Show toast from PHP messages
function initToasts() {
    // Check for success messages
    const successMessages = document.querySelectorAll('.alert-success');
    successMessages.forEach(alert => {
        const message = alert.textContent.trim();
        if (message) {
            showToast(message, 'success');
            alert.style.display = 'none';
        }
    });
    
    // Check for error messages
    const errorMessages = document.querySelectorAll('.alert-error, .alert-danger');
    errorMessages.forEach(alert => {
        const message = alert.textContent.trim();
        if (message) {
            showToast(message, 'error');
            alert.style.display = 'none';
        }
    });
    
    // Check for info messages
    const infoMessages = document.querySelectorAll('.alert-info');
    infoMessages.forEach(alert => {
        const message = alert.textContent.trim();
        if (message) {
            showToast(message, 'info');
            alert.style.display = 'none';
        }
    });
}

// Profile Dropdown Toggle
function toggleProfileDropdown() {
    var dropdown = document.getElementById('profileDropdownMenu');
    if (dropdown) {
        dropdown.classList.toggle('show');
    }
}

// Password Toggle Function (Global)
function togglePassword(inputId) {
    const input = document.getElementById(inputId);
    const icon = document.getElementById(inputId + '-toggle-icon');
    
    if (input && icon) {
        if (input.type === 'password') {
            input.type = 'text';
            icon.classList.remove('fa-eye');
            icon.classList.add('fa-eye-slash');
        } else {
            input.type = 'password';
            icon.classList.remove('fa-eye-slash');
            icon.classList.add('fa-eye');
        }
    }
}

// Close profile dropdown when clicking outside
document.addEventListener('click', function(event) {
    var profileDropdown = document.getElementById('profileDropdown');
    var profileBtn = document.getElementById('profileBtn');
    var dropdownMenu = document.getElementById('profileDropdownMenu');
    
    if (profileDropdown && dropdownMenu) {
        // Check if click is outside the profile dropdown
        if (!profileDropdown.contains(event.target)) {
            dropdownMenu.classList.remove('show');
        }
    }
});

$(document).ready(function() {
    // Initialize toasts from existing alerts
    initToasts();
    
    // Confirm delete actions with toast
    $('.btn-delete').on('click', function(e) {
        if (!confirm('Are you sure you want to delete this item?')) {
            e.preventDefault();
        }
    });
    
    // Modal handling
    $('.modal-trigger').on('click', function() {
        var modalId = $(this).data('modal');
        $('#' + modalId).show();
    });
    
    $('.close').on('click', function() {
        $(this).closest('.modal').hide();
    });
    
    $(window).on('click', function(e) {
        if ($(e.target).hasClass('modal')) {
            $('.modal').hide();
        }
    });
    
    // Auto-hide alerts (now handled by toasts)
    // setTimeout removed - using toast system instead
    
    // Form validation
    $('form').on('submit', function(e) {
        var isValid = true;
        $(this).find('input[required], select[required], textarea[required]').each(function() {
            if (!$(this).val()) {
                isValid = false;
                $(this).addClass('error');
            } else {
                $(this).removeClass('error');
            }
        });
        
        if (!isValid) {
            e.preventDefault();
            showToast('Please fill in all required fields', 'error');
        }
    });
    
    // Custom Multi-Select Dropdown with Checkboxes
    function initCustomMultiselect() {
        $('.custom-multiselect').each(function() {
            var $multiselect = $(this);
            var $display = $multiselect.find('.custom-multiselect-display');
            var $dropdown = $multiselect.find('.custom-multiselect-dropdown');
            var $options = $multiselect.find('.custom-multiselect-option');
            var $hiddenInputs = $multiselect.find('input[type="hidden"]');
            var $searchInput = $multiselect.find('.custom-multiselect-search input');
            
            // Toggle dropdown
            $display.on('click', function(e) {
                e.stopPropagation();
                $('.custom-multiselect-dropdown.show').not($dropdown).removeClass('show');
                $('.custom-multiselect-display.active').not($display).removeClass('active');
                $display.toggleClass('active');
                $dropdown.toggleClass('show');
                
                if ($dropdown.hasClass('show') && $searchInput.length) {
                    setTimeout(function() {
                        $searchInput.focus();
                    }, 100);
                }
            });
            
            // Handle checkbox change
            $options.find('input[type="checkbox"]').on('change', function() {
                updateDisplay();
                // Auto-submit form after a short delay to allow multiple selections
                var $form = $(this).closest('form');
                if ($form.length) {
                    clearTimeout(window.filterSubmitTimeout);
                    window.filterSubmitTimeout = setTimeout(function() {
                        $form.submit();
                    }, 500);
                }
            });
            
            // Update display text
            function updateDisplay() {
                var selected = [];
                $options.find('input[type="checkbox"]:checked').each(function() {
                    selected.push($(this).closest('.custom-multiselect-option').find('label').text());
                });
                
                var $placeholder = $display.find('.placeholder');
                var $count = $display.find('.selected-count');
                
                if (selected.length === 0) {
                    $placeholder.text('Select...').show();
                    $count.hide();
                } else {
                    $placeholder.hide();
                    var displayText = selected.length === 1 ? selected[0] : (selected.length <= 2 ? selected.join(', ') : selected.length + ' selected');
                    $count.text(displayText).show();
                }
            }
            
            // Initialize display on load
            updateDisplay();
            
            // Search functionality
            if ($searchInput.length) {
                $searchInput.on('input', function() {
                    var searchTerm = $(this).val().toLowerCase();
                    $options.each(function() {
                        var text = $(this).find('label').text().toLowerCase();
                        if (text.indexOf(searchTerm) > -1) {
                            $(this).show();
                        } else {
                            $(this).hide();
                        }
                    });
                });
            }
            
            // Close dropdown when clicking outside
            $(document).on('click', function(e) {
                if (!$(e.target).closest($multiselect).length) {
                    $display.removeClass('active');
                    $dropdown.removeClass('show');
                }
            });
        });
    }
    
    // Initialize on page load
    initCustomMultiselect();
    
    // Re-initialize after AJAX or dynamic content
    $(document).ajaxComplete(function() {
        initCustomMultiselect();
    });
    
    // Mobile Navigation - No toggle needed, menu is always visible
    
    // Removed auto-filter on search input - user will click Filter button instead
    
    // Apply filters button click handler
    $('#applyFiltersBtn').on('click', function() {
        filterTasks();
    });
    
    // Prevent form submission, use AJAX instead
    $('#filterForm').on('submit', function(e) {
        e.preventDefault();
        filterTasks();
    });
    
    // AJAX function to filter tasks
    function filterTasks() {
        var $form = $('#filterForm');
        if (!$form.length) return;
        
        // Show loading indicator
        var $tbody = $('#tasksTableBody');
        $tbody.html('<tr><td colspan="9" style="text-align: center; padding: 20px;"><div style="display: inline-block; padding: 10px;">Loading...</div></td></tr>');
        
        // Get form data
        var formData = $form.serialize();
        
        // Make AJAX request
        $.ajax({
            url: window.location.pathname,
            type: 'GET',
            data: formData,
            headers: {
                'X-Requested-With': 'XMLHttpRequest'
            },
            success: function(response) {
                if (typeof response === 'string') {
                    response = JSON.parse(response);
                }
                if (response.success) {
                    $tbody.html(response.html);
                    // Update statistics
                    if (response.stats) {
                        var $statsBar = $('.task-stats-bar');
                        if ($statsBar.length) {
                            $statsBar.replaceWith(response.stats);
                        } else {
                            $('.search-filters').after(response.stats);
                        }
                    }
                    // Update pagination
                    if (response.pagination) {
                        var $paginationContainer = $('#paginationContainer');
                        if ($paginationContainer.length) {
                            $paginationContainer.find('.pagination').remove();
                            $paginationContainer.prepend(response.pagination);
                            var countText = 'Showing ' + response.count + ' of ' + response.total_items + ' tasks';
                            $paginationContainer.find('span').last().text(countText);
                        }
                    }
                    // Re-initialize delete button handlers
                    $('.btn-delete').on('click', function(e) {
                        if (!confirm('Are you sure you want to delete this item?')) {
                            e.preventDefault();
                        }
                    });
                } else {
                    $tbody.html('<tr><td colspan="9" style="text-align: center; color: #999;">Error loading tasks</td></tr>');
                    showToast('Error loading tasks', 'error');
                }
            },
            error: function() {
                $tbody.html('<tr><td colspan="9" style="text-align: center; color: #999;">Error loading tasks. Please refresh the page.</td></tr>');
                showToast('Error loading tasks. Please refresh the page.', 'error');
            }
        });
    }
    
    // Toggle filters visibility
    $('#toggleFiltersBtn').on('click', function() {
        var $filters = $('#searchFilters');
        var $icon = $(this).find('i');
        
        if ($filters.is(':visible')) {
            $filters.slideUp(300);
            $icon.removeClass('fa-times').addClass('fa-filter');
            $(this).attr('title', 'Show Filters');
        } else {
            $filters.slideDown(300);
            $icon.removeClass('fa-filter').addClass('fa-times');
            $(this).attr('title', 'Hide Filters');
        }
    });
    
    // Keep filters hidden by default, but update checkboxes if project_id is in URL
    var urlParams = new URLSearchParams(window.location.search);
    
    // If project_id is in URL, check the corresponding checkbox (but don't show filters)
    if (urlParams.has('project_id')) {
        var projectId = urlParams.get('project_id');
        // Check the corresponding checkbox in the multiselect
        $('input[name="project_id[]"][value="' + projectId + '"]').prop('checked', true);
        // Update the multiselect display
        $('.custom-multiselect').each(function() {
            var $multiselect = $(this);
            var $options = $multiselect.find('.custom-multiselect-option');
            var selected = [];
            $options.find('input[type="checkbox"]:checked').each(function() {
                selected.push($(this).closest('.custom-multiselect-option').find('label').text());
            });
            var $display = $multiselect.find('.custom-multiselect-display');
            var $placeholder = $display.find('.placeholder');
            var $count = $display.find('.selected-count');
            if (selected.length === 0) {
                $placeholder.text('Select...').show();
                $count.hide();
            } else {
                $placeholder.hide();
                var countText = selected.length === 1 ? '1 selected' : selected.length + ' selected';
                $count.text(countText).show();
            }
        });
    }
    
    // Pagination links work normally - no AJAX needed
    // The links will navigate normally and preserve all URL parameters
});
