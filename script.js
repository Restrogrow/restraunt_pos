// Load SweetAlert2 dynamically if not already present
(function ensureSweetAlert(){
    if (!window.Swal) {
        const script = document.createElement('script');
        script.src = 'main/assets/js/sweetalert2.all.min.js';
        script.defer = true;
        document.head.appendChild(script);
    }
})();

function showFrontendAlert(message, type = 'info', options = {}) {
    if (window.Swal) {
        return Swal.fire({
            icon: type,
            text: message,
            confirmButtonColor: '#d97706',
            ...options
        });
    }
    return window.showFrontendAlert(message);
}

﻿// Navigation Toggle with debugging
document.addEventListener('DOMContentLoaded', function() {
    console.log('DOM loaded, initializing navigation...');
    const navToggle = document.querySelector('.nav-toggle');
    const navMenu = document.querySelector('.nav-menu');
    
    console.log('navToggle found:', navToggle);
    console.log('navMenu found:', navMenu);
    
    if (navToggle) {
        console.log('Adding click event to navToggle');
        navToggle.addEventListener('click', function(e) {
            console.log('Hamburger menu clicked!');
            e.preventDefault();
            e.stopPropagation();
            
            if (navMenu) {
                const isActive = navMenu.classList.contains('active');
                console.log('Menu is currently:', isActive ? 'active' : 'inactive');
                navMenu.classList.toggle('active');
                console.log('Menu is now:', navMenu.classList.contains('active') ? 'active' : 'inactive');
            } else {
                console.error('navMenu is null!');
            }
        });
    } else {
        console.error('navToggle not found!');
    }
});

// Smooth Scrolling - only for hash links on the same page
document.querySelectorAll('a[href^="#"]').forEach(anchor => {
    anchor.addEventListener('click', function (e) {
        const href = this.getAttribute('href');
        // Only handle hash links that exist on current page
        if (href !== '#' && href.startsWith('#') && document.querySelector(href)) {
            e.preventDefault();
            const target = document.querySelector(href);
            if (target) {
                target.scrollIntoView({
                    behavior: 'smooth',
                    block: 'start'
                });
                // Close mobile menu if open
                const navMenu = document.querySelector('.nav-menu');
                if (navMenu) {
                    navMenu.classList.remove('active');
                }
            }
        }
        // Let all other links (including /# links) work normally
    });
});

// Dashboard Tabs
const tabButtons = document.querySelectorAll('.tab-btn');
const dashboardPanels = document.querySelectorAll('.dashboard-panel');

tabButtons.forEach(button => {
    button.addEventListener('click', () => {
        const tab = button.dataset.tab;
        
        // Remove active class from all buttons and panels
        tabButtons.forEach(btn => btn.classList.remove('active'));
        dashboardPanels.forEach(panel => panel.classList.remove('active'));
        
        // Add active class to clicked button and corresponding panel
        button.classList.add('active');
        const panel = document.querySelector(`[data-panel="${tab}"]`);
        if (panel) {
            panel.classList.add('active');
        }
    });
});

// Feature Showcase Animation
const showcaseCards = document.querySelectorAll('.showcase-card');
let currentCard = 0;

if (showcaseCards.length > 0) {
    setInterval(() => {
        showcaseCards[currentCard].classList.remove('active');
        currentCard = (currentCard + 1) % showcaseCards.length;
        showcaseCards[currentCard].classList.add('active');
    }, 3000);
}

// Navbar Scroll Effect
const navbar = document.querySelector('.navbar');

window.addEventListener('scroll', () => {
    if (!navbar) return;
    navbar.classList.toggle('scrolled', window.pageYOffset > 40);
});

// Contact Form Submission
const contactForm = document.getElementById('contactForm');

if (contactForm) {
    contactForm.addEventListener('submit', async (e) => {
        e.preventDefault();
        
        // Get form data
        const formData = new FormData(contactForm);
        const submitButton = contactForm.querySelector('button[type="submit"]');
        const originalButtonText = submitButton ? submitButton.textContent : 'Send Message';
        
        // Disable button and show loading
        if (submitButton) {
            submitButton.disabled = true;
            submitButton.textContent = 'Sending...';
        }
        
        try {
            const response = await fetch('main/api/submit_contact.php', {
                method: 'POST',
                body: formData
            });
            
            // Check if response is ok
            if (!response.ok) {
                throw new Error(`HTTP error! status: ${response.status}`);
            }
            
            const result = await response.json();
            
            if (result.success) {
                showFrontendAlert(result.message || 'Thank you for your interest! We will contact you soon.');
                contactForm.reset();
            } else {
                showFrontendAlert(result.message || 'Error sending message. Please try again.');
            }
        } catch (error) {
            console.error('Error submitting contact form:', error);
            showFrontendAlert('Error sending message. Please check your connection and try again.');
        } finally {
            // Re-enable button
            if (submitButton) {
                submitButton.disabled = false;
                submitButton.textContent = originalButtonText;
            }
        }
    });
}

// Content is visible by default (no scroll-gated opacity system - see design brief:
// the previous IntersectionObserver fade-in setup could leave sections permanently
// invisible in some render paths). Hero content still gets a light on-load reveal
// via CSS animation only (see .hero-text / .hero-image in style.css).

// Enhanced Counter Animation for Stats
const animateCounter = (element, target, prefix = '', suffix = '') => {
    let current = 0;
    const duration = 2000;
    const increment = target / (duration / 16);
    const timer = setInterval(() => {
        current += increment;
        if (current >= target) {
            element.textContent = prefix + target + suffix;
            clearInterval(timer);
            // Add a bounce effect
            element.style.transform = 'scale(1.2)';
            setTimeout(() => {
                element.style.transform = 'scale(1)';
                element.style.transition = 'transform 0.3s ease';
            }, 100);
        } else {
            element.textContent = prefix + Math.floor(current) + suffix;
        }
    }, 16);
};

// Animate stats when they come into view
const statNumbers = document.querySelectorAll('.stat-number');
statNumbers.forEach(stat => {
    const observer = new IntersectionObserver((entries) => {
        entries.forEach(entry => {
            if (entry.isIntersecting) {
                const text = entry.target.textContent;
                // Extract optional leading prefix (e.g. "₹"), the number, and any suffix
                const match = text.match(/^(\D*)(\d+)(.*)$/);
                if (match) {
                    const prefix = match[1];
                    const number = parseInt(match[2]);
                    const suffix = match[3];
                    animateCounter(entry.target, number, prefix, suffix);
                    observer.unobserve(entry.target);
                }
            }
        });
    }, { threshold: 0.5 });

    observer.observe(stat);
});

// Optimized Parallax effect for hero section using requestAnimationFrame
let ticking = false;
const heroImage = document.querySelector('.hero-image');

function updateParallax() {
    const scrolled = window.pageYOffset;
    if (heroImage && scrolled < window.innerHeight) {
        const translateY = scrolled * 0.3;
        const opacity = 1 - (scrolled / window.innerHeight) * 0.3;
        heroImage.style.transform = `translate3d(0, ${translateY}px, 0)`;
        heroImage.style.opacity = opacity;
    }
    ticking = false;
}

window.addEventListener('scroll', () => {
    if (!ticking) {
        window.requestAnimationFrame(updateParallax);
        ticking = true;
    }
});

// Optimized scroll progress indicator using requestAnimationFrame
const createScrollProgress = () => {
    const progressBar = document.createElement('div');
    progressBar.style.cssText = `
        position: fixed;
        top: 0;
        left: 0;
        width: 0%;
        height: 3px;
        background: linear-gradient(90deg, var(--accent), var(--accent-dark));
        z-index: 10000;
        transform: translate3d(0, 0, 0);
        will-change: transform;
    `;
    document.body.appendChild(progressBar);
    
    let progressTicking = false;
    function updateProgress() {
        const windowHeight = document.documentElement.scrollHeight - window.innerHeight;
        const scrolled = (window.pageYOffset / windowHeight) * 100;
        progressBar.style.transform = `scaleX(${scrolled / 100})`;
        progressBar.style.transformOrigin = 'left';
        progressTicking = false;
    }
    
    window.addEventListener('scroll', () => {
        if (!progressTicking) {
            window.requestAnimationFrame(updateProgress);
            progressTicking = true;
        }
    });
};

createScrollProgress();

// Add ripple effect to buttons
document.querySelectorAll('.btn').forEach(button => {
    button.addEventListener('click', function(e) {
        const ripple = document.createElement('span');
        const rect = this.getBoundingClientRect();
        const size = Math.max(rect.width, rect.height);
        const x = e.clientX - rect.left - size / 2;
        const y = e.clientY - rect.top - size / 2;
        
        ripple.style.cssText = `
            position: absolute;
            width: ${size}px;
            height: ${size}px;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.6);
            left: ${x}px;
            top: ${y}px;
            transform: scale(0);
            animation: ripple 0.6s ease-out;
            pointer-events: none;
        `;
        
        this.style.position = 'relative';
        this.style.overflow = 'hidden';
        this.appendChild(ripple);
        
        setTimeout(() => ripple.remove(), 600);
    });
});

// Add ripple animation
const style = document.createElement('style');
style.textContent = `
    @keyframes ripple {
        to {
            transform: scale(4);
            opacity: 0;
        }
    }
`;
document.head.appendChild(style);

// Testimonials Auto-Scroll with Dots
(function() {
    var track = document.getElementById('testimonialsTrack');
    var dotsContainer = document.getElementById('testimonialsDots');
    if (!track || !dotsContainer) return;

    var cards = track.querySelectorAll('.testimonial-card');
    if (cards.length === 0) return;

    // Capture card width + gap BEFORE duplicating
    var cardWidth = cards[0].offsetWidth + 28;
    var dotCount = Math.max(2, Math.ceil(cards.length / 2));

    // Duplicate cards for seamless infinite loop
    var originalHTML = track.innerHTML;
    track.innerHTML = originalHTML + originalHTML;

    // Create dots
    for (var i = 0; i < dotCount; i++) {
        var dot = document.createElement('div');
        dot.className = 'dot' + (i === 0 ? ' active' : '');
        dot.dataset.index = i;
        dot.addEventListener('click', function() {
            var idx = parseInt(this.dataset.index);
            isPaused = true;
            track.scrollTo({ left: idx * cardWidth * 2, behavior: 'smooth' });
            clearTimeout(pauseTimeout);
            pauseTimeout = setTimeout(function() { isPaused = false; }, 5000);
        });
        dotsContainer.appendChild(dot);
    }

    var isPaused = false;
    var pauseTimeout = null;
    var intervalId = null;

    // Simple reliable interval-based auto-scroll
    function startAutoScroll() {
        if (intervalId) clearInterval(intervalId);
        intervalId = setInterval(function() {
            if (isPaused || document.hidden) return;
            track.scrollLeft += 1;
            if (track.scrollLeft >= track.scrollWidth / 2) {
                track.scrollLeft = 0;
            }
        }, 20); // ~50fps
    }

    // Pause on hover/touch
    track.addEventListener('mouseenter', function() { isPaused = true; });
    track.addEventListener('mouseleave', function() { isPaused = false; });
    track.addEventListener('touchstart', function() { isPaused = true; });
    track.addEventListener('touchend', function() {
        clearTimeout(pauseTimeout);
        pauseTimeout = setTimeout(function() { isPaused = false; }, 3000);
    });

    // Update dots on manual scroll
    track.addEventListener('scroll', function() {
        var scrollL = this.scrollLeft;
        var activeIdx = Math.round(scrollL / (cardWidth * 2));
        if (activeIdx >= dotCount) activeIdx = activeIdx % dotCount;
        if (activeIdx < 0) activeIdx = 0;
        dotsContainer.querySelectorAll('.dot').forEach(function(d, i) {
            d.classList.toggle('active', i === activeIdx);
        });
    });

    startAutoScroll();
})();

