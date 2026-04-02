// User Search Functionality
document.addEventListener('DOMContentLoaded', function() {
    const searchInput = document.getElementById('userSearch');
    const searchResults = document.getElementById('searchResults');
    const selectedUsersList = document.getElementById('selectedUsersList');
    const participantsInput = document.getElementById('participants');
    const includeMyself = document.getElementById('include_myself');
    const amountInput = document.getElementById('amount');
    const splitPreview = document.getElementById('splitPreview');
    
    // Store selected users with their usernames
    let selectedUsers = new Map(); // Store username with id
    let searchTimeout;
    
    if (searchInput) {
        searchInput.addEventListener('input', function() {
            clearTimeout(searchTimeout);
            const query = this.value.trim();
            
            if (query.length < 2) {
                searchResults.innerHTML = '';
                return;
            }
            
            searchTimeout = setTimeout(() => {
                searchUsers(query);
            }, 300);
        });
    }
    
    function searchUsers(query) {
        fetch(`/api/search-users?q=${encodeURIComponent(query)}`)
            .then(response => response.json())
            .then(users => {
                displaySearchResults(users);
            })
            .catch(error => console.error('Error:', error));
    }
    
    function displaySearchResults(users) {
        if (!searchResults) return;
        
        if (users.length === 0) {
            searchResults.innerHTML = '<div class="search-result-item">No users found</div>';
            return;
        }
        
        searchResults.innerHTML = users
            .filter(user => !selectedUsers.has(user.id.toString()))
            .map(user => `
                <div class="search-result-item" onclick="addUser(${user.id}, '${user.username}')">
                    <span class="material-icons">person</span>
                    ${user.username}
                </div>
            `).join('');
    }
    
    window.addUser = function(userId, username) {
        if (selectedUsers.has(userId.toString())) return;
        
        selectedUsers.set(userId.toString(), username);
        updateSelectedUsersList();
        updateParticipantsInput();
        updateSplitPreview();
        
        // Clear search
        if (searchInput) {
            searchInput.value = '';
            searchResults.innerHTML = '';
        }
    };
    
    window.removeUser = function(userId) {
        selectedUsers.delete(userId.toString());
        updateSelectedUsersList();
        updateParticipantsInput();
        updateSplitPreview();
    };
    
    function updateSelectedUsersList() {
        if (!selectedUsersList) return;
        
        if (selectedUsers.size === 0) {
            selectedUsersList.innerHTML = '<p class="text-secondary">No participants selected yet</p>';
            return;
        }
        
        // Show usernames instead of IDs
        selectedUsersList.innerHTML = Array.from(selectedUsers.entries()).map(([id, username]) => `
            <span class="selected-user-tag">
                ${username}
                <span class="material-icons remove" onclick="removeUser(${id})">close</span>
            </span>
        `).join('');
    }
    
    function updateParticipantsInput() {
        if (participantsInput) {
            participantsInput.value = Array.from(selectedUsers.keys()).join(',');
        }
    }
    
    function updateSplitPreview() {
        if (!splitPreview || !amountInput || !includeMyself) return;
        
        const amount = parseFloat(amountInput.value);
        if (isNaN(amount) || amount <= 0) {
            splitPreview.style.display = 'none';
            return;
        }
        
        let participantCount = selectedUsers.size;
        const includeMe = includeMyself.checked;
        
        if (includeMe) {
            participantCount++;
        }
        
        if (participantCount === 0) {
            splitPreview.style.display = 'none';
            return;
        }
        
        const eachAmount = amount / participantCount;
        const yourShare = includeMe ? eachAmount : 0;
        const othersTotal = amount - yourShare;
        
        // Update all preview elements
        document.getElementById('previewTotal').textContent = amount.toFixed(2);
        document.getElementById('previewCount').textContent = participantCount;
        document.getElementById('previewEach').textContent = eachAmount.toFixed(2);
        document.getElementById('previewEachNote').textContent = eachAmount.toFixed(2);
        document.getElementById('previewYourShare').textContent = '$' + yourShare.toFixed(2);
        document.getElementById('previewOthersTotal').textContent = '$' + othersTotal.toFixed(2);
        
        splitPreview.style.display = 'block';
    }
    
    if (amountInput) {
        amountInput.addEventListener('input', updateSplitPreview);
    }
    
    if (includeMyself) {
        includeMyself.addEventListener('change', updateSplitPreview);
    }
});

// Payment Form Validation
document.addEventListener('DOMContentLoaded', function() {
    const paymentForm = document.getElementById('paymentForm');
    
    if (paymentForm) {
        paymentForm.addEventListener('submit', function(e) {
            const participants = document.getElementById('participants').value;
            const amount = document.getElementById('amount').value;
            
            if (!participants && !document.getElementById('include_myself').checked) {
                e.preventDefault();
                alert('Please select at least one participant');
                return;
            }
            
            if (parseFloat(amount) <= 0) {
                e.preventDefault();
                alert('Please enter a valid amount');
                return;
            }
        });
    }
});

// Settlement Functions
window.settlePayment = function(participantId, paymentId, description) {
    const amountInput = document.getElementById(`amount-${participantId}`);
    const amount = amountInput ? amountInput.value : null;
    const statusDiv = document.getElementById(`status-${participantId}`);
    
    if (!participantId || !paymentId) {
        alert('Error: Missing payment information');
        return;
    }
    
    let confirmMessage = amount && parseFloat(amount) > 0 
        ? `Record a settlement of $${parseFloat(amount).toFixed(2)} for "${description}"?`
        : `Mark this payment as fully settled for "${description}"?`;
    
    if (!confirm(confirmMessage)) return;
    
    // Show loading state
    if (statusDiv) {
        statusDiv.innerHTML = '<span class="material-icons">hourglass_empty</span> Processing...';
    }
    
    const formData = new FormData();
    formData.append('participant_id', participantId);
    formData.append('payment_id', paymentId);
    if (amount && parseFloat(amount) > 0) {
        formData.append('amount', amount);
    }
    
    fetch('/api/settle', {
        method: 'POST',
        body: formData
    })
    .then(response => {
        if (!response.ok) {
            throw new Error('Network response was not ok');
        }
        return response.json();
    })
    .then(data => {
        if (data.success) {
            if (statusDiv) {
                statusDiv.innerHTML = '<span class="material-icons success">check_circle</span> Settled!';
                // Disable the input and button after successful settlement
                if (amountInput) {
                    amountInput.disabled = true;
                }
                const settleBtn = document.querySelector(`button[onclick*="${participantId}"]`);
                if (settleBtn) {
                    settleBtn.disabled = true;
                    settleBtn.classList.add('disabled');
                }
                setTimeout(() => {
                    location.reload();
                }, 1500);
            } else {
                alert('Success: ' + data.message);
                location.reload();
            }
        } else {
            if (statusDiv) {
                statusDiv.innerHTML = '<span class="material-icons error">error</span> Failed: ' + data.message;
            }
            alert('Error: ' + data.message);
        }
    })
    .catch(error => {
        console.error('Error:', error);
        if (statusDiv) {
            statusDiv.innerHTML = '<span class="material-icons error">error</span> Connection error';
        }
        alert('An error occurred while processing the settlement');
    });
};

// Delete Payment
window.deletePayment = function(paymentId) {
    if (!confirm('Are you sure you want to delete this payment? This action cannot be undone.')) return;
    
    fetch(`/api/delete-payment/${paymentId}`, {
        method: 'DELETE'
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            alert('Payment deleted successfully');
            location.reload();
        } else {
            alert('Error: ' + data.message);
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('An error occurred');
    });
};

// Auto-hide alerts after 5 seconds
document.addEventListener('DOMContentLoaded', function() {
    const alerts = document.querySelectorAll('.alert');
    alerts.forEach(alert => {
        setTimeout(() => {
            alert.style.opacity = '0';
            setTimeout(() => {
                alert.style.display = 'none';
            }, 300);
        }, 5000);
    });
});

// Toggle password visibility for installation forms
window.togglePassword = function(fieldId) {
    const field = document.getElementById(fieldId);
    const icon = event.currentTarget;
    
    if (field.type === 'password') {
        field.type = 'text';
        icon.textContent = 'visibility_off';
    } else {
        field.type = 'password';
        icon.textContent = 'visibility';
    }
};

// Format currency input
document.addEventListener('DOMContentLoaded', function() {
    const currencyInputs = document.querySelectorAll('input[type="number"]');
    currencyInputs.forEach(input => {
        input.addEventListener('blur', function() {
            if (this.value) {
                this.value = parseFloat(this.value).toFixed(2);
            }
        });
    });
});

// Add smooth scrolling to all links
document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('a[href^="#"]').forEach(anchor => {
        anchor.addEventListener('click', function (e) {
            e.preventDefault();
            document.querySelector(this.getAttribute('href')).scrollIntoView({
                behavior: 'smooth'
            });
        });
    });
});
// Mobile menu toggle
document.addEventListener('DOMContentLoaded', function() {
    // Create mobile menu button
    const navWrapper = document.querySelector('.nav-wrapper');
    const navMenu = document.querySelector('.nav-menu');
    
    if (navWrapper && navMenu && window.innerWidth <= 768) {
        const mobileBtn = document.createElement('button');
        mobileBtn.className = 'mobile-menu-btn';
        mobileBtn.innerHTML = '<span class="material-icons">menu</span>';
        mobileBtn.setAttribute('aria-label', 'Toggle menu');
        
        // Insert button after brand logo
        const brandLogo = document.querySelector('.brand-logo');
        if (brandLogo) {
            brandLogo.after(mobileBtn);
        }
        
        // Toggle menu on button click
        mobileBtn.addEventListener('click', function(e) {
            e.stopPropagation();
            navMenu.classList.toggle('show');
            
            // Change icon based on menu state
            const icon = this.querySelector('.material-icons');
            if (navMenu.classList.contains('show')) {
                icon.textContent = 'close';
            } else {
                icon.textContent = 'menu';
            }
        });
        
        // Close menu when clicking outside
        document.addEventListener('click', function(e) {
            if (!navWrapper.contains(e.target) && navMenu.classList.contains('show')) {
                navMenu.classList.remove('show');
                mobileBtn.querySelector('.material-icons').textContent = 'menu';
            }
        });
        
        // Handle user menu in mobile
        const userMenu = document.querySelector('.user-menu');
        if (userMenu) {
            const userTrigger = userMenu.querySelector('.user-menu-trigger');
            userTrigger.addEventListener('click', function(e) {
                e.preventDefault();
                e.stopPropagation();
                userMenu.classList.toggle('active');
            });
        }
    }
    
    // Handle window resize
    window.addEventListener('resize', function() {
        const mobileBtn = document.querySelector('.mobile-menu-btn');
        if (window.innerWidth > 768) {
            if (mobileBtn) mobileBtn.remove();
            if (navMenu) navMenu.classList.remove('show');
        }
    });
});
