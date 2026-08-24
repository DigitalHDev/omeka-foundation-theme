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
        setupItemDetails();
        setupViewSwitcher();
        setupLightbox();
        setupSearchFilters();
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
        var src = grid.getAttribute('data-src');
        if (!src) {
            initMasonryGrid(grid);
            return;
        }
        // Tiles are fetched separately; the section stays hidden until they arrive.
        fetch(src, { credentials: 'same-origin' })
            .then(function (response) {
                return response.ok ? response.text() : '';
            })
            .then(function (html) {
                if (!html.trim()) {
                    return;
                }
                grid.insertAdjacentHTML('beforeend', html);
                revealSelectedItems();
                initMasonryGrid(grid);
            })
            .catch(function () {
                /* leave the section hidden */
            });
    }

    function revealSelectedItems() {
        ['selected-items-divider', 'selected-items-section'].forEach(function (id) {
            var el = document.getElementById(id);
            if (el) {
                el.removeAttribute('hidden');
            }
        });
    }

    function initMasonryGrid(grid) {
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
        $button.closest('.load-more-container').removeAttr('hidden').show();

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
     * Item page: "show more" details drawer (Person/Org hero)
     * ------------------------------------------------------------------ */
    function setupItemDetails() {
        var $toggle = $('#toggle-item-details');
        var $drawer = $('#item-details-drawer');
        if (!$toggle.length || !$drawer.length) {
            return;
        }
        $toggle.on('click', function () {
            var open = $drawer.toggleClass('details-closed').hasClass('details-closed') === false;
            $drawer.toggleClass('details-opened', open);
            $toggle.attr('aria-expanded', open ? 'true' : 'false');
            $toggle.text(open ? 'להציג פחות –' : 'להציג עוד +');
        });
    }

    /* ------------------------------------------------------------------ *
     * Item page: grid / list view switcher for related items
     * ------------------------------------------------------------------ */
    function setupViewSwitcher() {
        var $container = $('#results-container');
        if (!$container.length) {
            return;
        }
        var $grid = $('#grid-view-btn');
        var $list = $('#list-view-btn');

        $grid.on('click', function () {
            $container.addClass('is-grid').removeClass('is-list');
            $grid.addClass('active');
            $list.removeClass('active');
        });
        $list.on('click', function () {
            $container.addClass('is-list').removeClass('is-grid');
            $list.addClass('active');
            $grid.removeClass('active');
        });
    }

    /* ------------------------------------------------------------------ *
     * Item page: installation-photos lightbox (images + YouTube videos)
     * ------------------------------------------------------------------ */
    function setupLightbox() {
        var $overlay = $('#image-lightbox');
        var $thumbs = $('.gallery-thumb-btn');
        if (!$overlay.length || !$thumbs.length) {
            return;
        }

        var $image = $('#lightbox-image');
        var $video = $('#lightbox-video');
        var $caption = $('#lightbox-caption');
        var $download = $overlay.find('.lightbox-download');
        var items = $thumbs.toArray();
        var current = 0;

        function render() {
            var el = items[current];
            var kind = el.getAttribute('data-lightbox-kind');
            var src = el.getAttribute('data-lightbox-src') || '';
            var caption = el.getAttribute('data-lightbox-caption') || '';

            $caption.text(caption);
            if (kind === 'video') {
                $image.attr('src', '').hide();
                $video.html('<iframe src="' + src + '" title="' + caption +
                    '" frameborder="0" allow="accelerometer; autoplay; encrypted-media; picture-in-picture" allowfullscreen></iframe>');
                $video.prop('hidden', false).show();
                $download.hide();
            } else {
                $video.html('').prop('hidden', true).hide();
                $image.attr('src', src).attr('alt', caption).show();
                $download.attr('data-href', el.getAttribute('data-lightbox-download') || src).show();
            }
        }

        function open(index) {
            current = index;
            render();
            $overlay.addClass('active').attr('aria-hidden', 'false');
        }
        function close() {
            $overlay.removeClass('active').attr('aria-hidden', 'true');
            $video.html('').prop('hidden', true); // stop video playback
            $image.attr('src', '');
        }
        function step(delta) {
            current = (current + delta + items.length) % items.length;
            render();
        }

        $thumbs.each(function (i, el) {
            $(el).on('click', function () { open(i); });
        });
        $overlay.find('.lightbox-close').on('click', close);
        $overlay.find('.lightbox-prev').on('click', function () { step(-1); });
        $overlay.find('.lightbox-next').on('click', function () { step(1); });
        $download.on('click', function () {
            var href = $(this).attr('data-href');
            if (href) {
                window.open(href, '_blank', 'noopener');
            }
        });
        $overlay.on('click', function (e) {
            if (e.target === this) {
                close();
            }
        });
        $(document).on('keyup', function (e) {
            if (!$overlay.hasClass('active')) {
                return;
            }
            if (e.key === 'Escape') { close(); }
            else if (e.key === 'ArrowRight') { step(1); }
            else if (e.key === 'ArrowLeft') { step(-1); }
        });
    }

    /* ------------------------------------------------------------------ *
     * Search results: filter drawer toggle + domain/event-type column gating
     * ------------------------------------------------------------------ */
    function setupSearchFilters() {
        var $drawer = $('#filter-drawer');
        if (!$drawer.length) {
            return;
        }

        // Open / close the filter drawer.
        var $toggle = $('#toggle-filters');
        $toggle.on('click', function () {
            var open = !$drawer.hasClass('drawer-open');
            $drawer.toggleClass('drawer-open', open).toggleClass('drawer-closed', !open);
            $toggle.attr('aria-expanded', open ? 'true' : 'false');
            $toggle.find('i')
                .toggleClass('ph-caret-up', open)
                .toggleClass('ph-caret-down', !open);
        });

        // Domain (תחום) and event-type (סוג האירוע) columns apply only when
        // Events (16) or Documents (15) are among the selected item types.
        var $typeInputs = $drawer.find('.filter-col-types input[type="checkbox"]');
        var $depCols = $drawer.find('.filter-col-domains, .filter-col-etypes');
        function refreshDependentColumns() {
            var enabled = false;
            $typeInputs.each(function () {
                var v = parseInt(this.value, 10);
                if (this.checked && (v === 15 || v === 16)) {
                    enabled = true;
                }
            });
            $depCols.toggleClass('col-disabled', !enabled);
            $depCols.find('input[type="checkbox"]').prop('disabled', !enabled);
        }
        $typeInputs.on('change', refreshDependentColumns);
        refreshDependentColumns();
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
