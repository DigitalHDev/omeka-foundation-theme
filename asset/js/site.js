/**
 * Site chrome + home-page interactions.
 * Depends on jQuery (provided by Omeka core). Masonry + imagesLoaded are loaded
 * for pages that use the "Selected items" grid; all blocks below are guarded so
 * the script is safe to load on every page.
 */
(function ($) {
    'use strict';

    $(function () {
        setupOverlays();
        setupMasonry();
        setupCopyEmail();
    });

    /* ------------------------------------------------------------------ *
     * Search panel + hamburger sidebar
     * ------------------------------------------------------------------ */
    function setupOverlays() {
        var $searchPanel = $('#search-panel');
        var $sidebar = $('#sidebar-overlay');

        function open($el, $trigger) {
            $el.addClass('active').attr('aria-hidden', 'false');
            if ($trigger) {
                $trigger.attr('aria-expanded', 'true');
            }
        }
        function close($el, $trigger) {
            $el.removeClass('active').attr('aria-hidden', 'true');
            if ($trigger) {
                $trigger.attr('aria-expanded', 'false');
            }
        }

        var $searchTrigger = $('#search-trigger');
        var $hamburger = $('#hamburger-menu');

        $searchTrigger.on('click keydown', function (e) {
            if (e.type === 'keydown' && e.key !== 'Enter' && e.key !== ' ') {
                return;
            }
            e.preventDefault();
            close($sidebar, $hamburger);
            open($searchPanel, $searchTrigger);
            $('#panel-search-input').trigger('focus');
        });

        $hamburger.on('click keydown', function (e) {
            if (e.type === 'keydown' && e.key !== 'Enter' && e.key !== ' ') {
                return;
            }
            e.preventDefault();
            close($searchPanel, $searchTrigger);
            open($sidebar, $hamburger);
        });

        $('.close-search-panel').on('click', function () {
            close($searchPanel, $searchTrigger);
        });
        $('#close-sidebar').on('click', function () {
            close($sidebar, $hamburger);
        });

        $(document).on('keyup', function (e) {
            if (e.key === 'Escape') {
                close($searchPanel, $searchTrigger);
                close($sidebar, $hamburger);
            }
        });
    }

    /* ------------------------------------------------------------------ *
     * "Selected items" masonry grid + load more
     * ------------------------------------------------------------------ */
    function setupMasonry() {
        var grid = document.querySelector('.masonry-grid');
        if (!grid || typeof Masonry === 'undefined') {
            return;
        }

        var batchSize = parseInt(grid.getAttribute('data-batch'), 10) || 8;
        var initial = parseInt(grid.getAttribute('data-initial'), 10) || batchSize;

        // Detach every item beyond the initial batch; reveal them on demand.
        var pending = Array.prototype.slice.call(grid.querySelectorAll('.grid-item'));
        pending = pending.slice(initial);
        pending.forEach(function (item) {
            item.parentNode.removeChild(item);
        });

        var msnry;
        function initMasonry() {
            msnry = new Masonry(grid, {
                itemSelector: '.grid-item',
                columnWidth: '.grid-sizer',
                percentPosition: true,
                originLeft: false // RTL
            });
        }

        if (typeof imagesLoaded !== 'undefined') {
            imagesLoaded(grid, initMasonry);
        } else {
            initMasonry();
        }

        var $button = $('#load-more');
        if (!pending.length) {
            $button.closest('.load-more-container').hide();
            return;
        }

        $button.on('click', function () {
            var batch = pending.splice(0, batchSize);
            batch.forEach(function (item) {
                grid.appendChild(item);
            });
            if (msnry && typeof imagesLoaded !== 'undefined') {
                imagesLoaded(grid, function () {
                    msnry.appended(batch);
                    msnry.layout();
                });
            } else if (msnry) {
                msnry.appended(batch);
                msnry.layout();
            }
            if (!pending.length) {
                $button.closest('.load-more-container').hide();
            }
        });
    }

    /* ------------------------------------------------------------------ *
     * Copy-email button (Contact). Ready for the Contact page.
     * ------------------------------------------------------------------ */
    function setupCopyEmail() {
        $('.copy-email-btn').on('click', function () {
            var email = $(this).attr('data-email');
            if (!email) {
                return;
            }
            var $btn = $(this);
            var done = function () {
                var original = $btn.attr('data-label-default') || $btn.text();
                $btn.text($btn.attr('data-label-copied') || 'הועתק');
                setTimeout(function () { $btn.text(original); }, 2000);
            };
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(email).then(done).catch(function () {});
            } else {
                var tmp = document.createElement('input');
                tmp.value = email;
                document.body.appendChild(tmp);
                tmp.select();
                try { document.execCommand('copy'); done(); } catch (err) {}
                document.body.removeChild(tmp);
            }
        });
    }
})(jQuery);
