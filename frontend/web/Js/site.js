/**
 * HealthFirst Site JavaScript - Minimal custom JS leveraging Bootstrap
 */

$(document).ready(function () {
    // Initialize Bootstrap tooltips (if any)
    var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
    var tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
        return new bootstrap.Tooltip(tooltipTriggerEl);
    });

    // Initialize Bootstrap popovers (if any)
    var popoverTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="popover"]'));
    var popoverList = popoverTriggerList.map(function (popoverTriggerEl) {
        return new bootstrap.Popover(popoverTriggerEl);
    });

    // Auto-hide alerts after 5 seconds
    $('.alert').each(function () {
        var alert = $(this);
        setTimeout(function () {
            alert.fadeOut('slow');
        }, 5000);
    });

    // Search form validation (if exists)
    $('form[data-form="search"]').on('submit', function (e) {
        var hasInput = false;
        $(this).find('input[type="text"]').each(function () {
            if ($(this).val().trim() !== '') {
                hasInput = true;
                return false;
            }
        });

        if (!hasInput) {
            e.preventDefault();
            alert('Please enter at least one search criteria.');
            return false;
        }
    });

    // Enhanced dropdown behavior for user menu
    $('#userDropdown').on('show.bs.dropdown', function () {
        // Optional: Add any custom logic when dropdown opens
    });

    $('#userDropdown').on('hide.bs.dropdown', function () {
        // Optional: Add any custom logic when dropdown closes
    });

    // Smooth scrolling for anchor links
    $('a[href^="#"]').on('click', function (e) {
        var target = $(this.getAttribute('href'));
        if (target.length) {
            e.preventDefault();
            $('html, body').stop().animate({
                scrollTop: target.offset().top - 70
            }, 1000);
        }
    });

    // Form enhancements
    $('.form-control').on('focus', function () {
        $(this).parent().addClass('focused');
    }).on('blur', function () {
        $(this).parent().removeClass('focused');
    });

    // Table row click handling (if needed)
    $('.table-hover tbody tr').on('click', function (e) {
        // Only trigger if not clicking on a button or link
        if (!$(e.target).is('button, a, .btn')) {
            var link = $(this).find('a.btn').first();
            if (link.length) {
                window.location.href = link.attr('href');
            }
        }
    });
});