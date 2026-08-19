// assets/js/main.js
// Javascript interactivity for MSC Research Publications Aggregator v2

document.addEventListener('DOMContentLoaded', () => {
    // 1. Theme Toggle Management
    const themeToggleBtn = document.getElementById('theme-toggle');
    const themeIcon = document.getElementById('theme-icon');
    
    // Check local storage for dark mode preference
    const savedTheme = localStorage.getItem('theme');
    const prefersLight = savedTheme === 'light';
    
    if (prefersLight) {
        document.body.classList.add('light-mode');
        if (themeIcon) {
            themeIcon.className = 'fas fa-moon'; // Show moon to allow switching back to dark
        }
    } else {
        document.body.classList.remove('light-mode');
        if (themeIcon) {
            themeIcon.className = 'fas fa-sun'; // Show sun to allow switching to light
        }
    }

    if (themeToggleBtn) {
        themeToggleBtn.addEventListener('click', () => {
            const isLight = document.body.classList.toggle('light-mode');
            localStorage.setItem('theme', isLight ? 'light' : 'dark');
            
            if (themeIcon) {
                themeIcon.className = isLight ? 'fas fa-moon' : 'fas fa-sun';
            }
            
            // Re-render charts with new theme colors if exist
            if (window.renderDashboardChart) {
                window.renderDashboardChart();
            }
        });
    }

    // 2. Real-time Client-Side Search/Filters (for elements present on page)
    const searchInput = document.getElementById('search-input');
    const itemsToSearch = document.querySelectorAll('.searchable-item');
    
    if (searchInput && itemsToSearch.length > 0) {
        searchInput.addEventListener('input', (e) => {
            const query = e.target.value.toLowerCase().trim();
            itemsToSearch.forEach(item => {
                const textContent = item.textContent.toLowerCase();
                if (textContent.includes(query)) {
                    item.style.display = '';
                    item.classList.add('animate-fade-in');
                } else {
                    item.style.display = 'none';
                    item.classList.remove('animate-fade-in');
                }
            });
        });
    }

    // 3. Department Quick Filtering
    const deptButtons = document.querySelectorAll('.dept-btn');
    if (deptButtons.length > 0) {
        deptButtons.forEach(btn => {
            btn.addEventListener('click', () => {
                // Toggle active state
                deptButtons.forEach(b => b.classList.remove('active'));
                btn.classList.add('active');
                
                const selectedDept = btn.getAttribute('data-dept');
                itemsToSearch.forEach(item => {
                    const itemDept = item.getAttribute('data-dept');
                    if (selectedDept === 'all' || itemDept === selectedDept) {
                        item.style.display = '';
                    } else {
                        item.style.display = 'none';
                    }
                });
            });
        });
    }
});
