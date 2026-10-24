// Home page (index.html). The other pages keep script.js.

// SweetAlert2 (77 KB) is fetched only when an alert is actually shown (after the form is sent).
var sweetAlertSrc = 'main/assets/js/sweetalert2.all.min.js';
var sweetAlertLoading = null;
function loadSweetAlert() {
    if (window.Swal) return Promise.resolve();
    if (!sweetAlertLoading) {
        sweetAlertLoading = new Promise(function (resolve, reject) {
            var script = document.createElement('script');
            script.src = sweetAlertSrc;
            script.onload = resolve;
            script.onerror = reject;
            document.head.appendChild(script);
        });
    }
    return sweetAlertLoading;
}
function showFrontendAlert(message, type) {
    return loadSweetAlert().then(function () {
        return Swal.fire({ icon: type || 'info', text: message, confirmButtonColor: '#c1440e' });
    }).catch(function () { alert(message); });
}

// Navigation: phone menu and the line under the bar once the page scrolls
(function () {
    var nav = document.querySelector('.nav');
    var toggle = document.querySelector('.nav-toggle');
    var menu = document.getElementById('navMenu');
    function setMenu(open) {
        menu.classList.toggle('open', open);
        toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
        toggle.setAttribute('aria-label', open ? 'Close menu' : 'Open menu');
        document.documentElement.style.overflow = open ? 'hidden' : '';
    }
    if (toggle && menu) {
        toggle.addEventListener('click', function () { setMenu(!menu.classList.contains('open')); });
        menu.addEventListener('click', function (e) { if (e.target.closest('a')) setMenu(false); });
        document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && menu.classList.contains('open')) setMenu(false); });
        window.addEventListener('resize', function () { if (window.innerWidth > 1024 && menu.classList.contains('open')) setMenu(false); });
    }
    if (nav) {
        var onScroll = function () { nav.classList.toggle('scrolled', window.pageYOffset > 8); };
        window.addEventListener('scroll', onScroll, { passive: true });
        onScroll();
    }
})();

// Product tour tabs (arrow keys move between tabs)
(function () {
    var tabs = Array.prototype.slice.call(document.querySelectorAll('.tour-tabs [role="tab"]'));
    if (!tabs.length) return;
    function select(tab, focus) {
        tabs.forEach(function (t) {
            var on = t === tab;
            t.setAttribute('aria-selected', on ? 'true' : 'false');
            t.tabIndex = on ? 0 : -1;
            document.getElementById(t.getAttribute('aria-controls')).hidden = !on;
        });
        if (focus) tab.focus();
        if (tab.scrollIntoView && tab.parentNode.scrollWidth > tab.parentNode.clientWidth) {
            tab.parentNode.scrollTo({ left: tab.offsetLeft - 16, behavior: 'smooth' });
        }
    }
    tabs.forEach(function (tab, i) {
        tab.addEventListener('click', function () { select(tab, false); });
        tab.addEventListener('keydown', function (e) {
            var next = e.key === 'ArrowRight' ? i + 1 : e.key === 'ArrowLeft' ? i - 1 : e.key === 'Home' ? 0 : e.key === 'End' ? tabs.length - 1 : null;
            if (next === null) return;
            e.preventDefault();
            select(tabs[(next + tabs.length) % tabs.length], true);
        });
    });
})();

// Commission calculator
(function () {
    var orders = document.getElementById('calcOrders'), aov = document.getElementById('calcAov'), rate = document.getElementById('calcRate');
    if (!orders || !aov || !rate) return;
    // Indian grouping: 10,80,000
    function inr(n) { return '₹' + Math.round(n).toLocaleString('en-IN'); }
    function update() {
        var month = orders.value * aov.value * (rate.value / 100) * 30;
        document.getElementById('cOrders').textContent = orders.value;
        document.getElementById('cAov').textContent = inr(aov.value);
        document.getElementById('cRate').textContent = rate.value + '%';
        document.getElementById('calcMonth').textContent = inr(month);
        document.getElementById('calcYear').textContent = inr(month * 12);
        document.getElementById('calcThird').textContent = inr(month / 3);
    }
    [orders, aov, rate].forEach(function (el) { el.addEventListener('input', update); });
    update();
})();

// Contact form
(function () {
    var contactForm = document.getElementById('contactForm');
    if (!contactForm) return;
    contactForm.addEventListener('submit', function (e) {
        e.preventDefault();
        var submitButton = contactForm.querySelector('button[type="submit"]');
        var originalButtonText = submitButton.textContent;
        submitButton.disabled = true;
        submitButton.textContent = 'Sending...';
        fetch('main/api/submit_contact.php', { method: 'POST', body: new FormData(contactForm) })
            .then(function (response) {
                if (!response.ok) throw new Error('HTTP error! status: ' + response.status);
                return response.json();
            })
            .then(function (result) {
                if (result.success) {
                    showFrontendAlert(result.message || 'Thank you for your interest! We will contact you soon.', 'success');
                    contactForm.reset();
                } else {
                    showFrontendAlert(result.message || 'Error sending message. Please try again.', 'error');
                }
            })
            .catch(function (error) {
                console.error('Error submitting contact form:', error);
                showFrontendAlert('Error sending message. Please check your connection and try again.', 'error');
            })
            .then(function () {
                submitButton.disabled = false;
                submitButton.textContent = originalButtonText;
            });
    });
})();
