(function () {
    function ready(callback) {
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', callback, { once: true });
            return;
        }
        callback();
    }

    ready(function () {
        var hero = document.querySelector('.pt-new-hero');
        if (!hero) {
            return;
        }

        var slides = Array.prototype.slice.call(hero.querySelectorAll('.pt-new-slide'));
        var controls = Array.prototype.slice.call(hero.querySelectorAll('.pt-new-hero__arrows span'));
        if (slides.length < 2) {
            slides.forEach(function (slide) {
                slide.classList.add('is-active');
            });
            return;
        }

        var current = 0;
        var timer = null;

        function show(index) {
            current = (index + slides.length) % slides.length;
            slides.forEach(function (slide, slideIndex) {
                var active = slideIndex === current;
                slide.classList.toggle('is-active', active);
                slide.setAttribute('aria-hidden', active ? 'false' : 'true');
                slide.style.opacity = active ? '1' : '0';
                slide.style.zIndex = active ? '1' : '0';
            });
        }

        function restart() {
            if (timer) {
                window.clearInterval(timer);
            }
            timer = window.setInterval(function () {
                show(current + 1);
            }, 7000);
        }

        hero.classList.add('is-slider-ready');

        controls.forEach(function (control, index) {
            control.dataset.slideControl = index === 0 ? 'prev' : 'next';
            control.setAttribute('role', 'button');
            control.setAttribute('tabindex', '0');
            control.setAttribute('aria-label', index === 0 ? 'Poprzedni slajd' : 'Następny slajd');
        });

        hero.addEventListener('click', function (event) {
            var control = event.target.closest('[data-slide-control]');
            if (!control || !hero.contains(control)) {
                return;
            }
            show(current + (control.dataset.slideControl === 'prev' ? -1 : 1));
            restart();
        });

        hero.addEventListener('keydown', function (event) {
            if (event.key !== 'Enter' && event.key !== ' ') {
                return;
            }
            var control = event.target.closest('[data-slide-control]');
            if (!control || !hero.contains(control)) {
                return;
            }
            event.preventDefault();
            control.click();
        });

        show(0);
        restart();
    });
}());

(function () {
    function ready(callback) {
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', callback, { once: true });
            return;
        }
        callback();
    }

    ready(function () {
        var strips = Array.prototype.slice.call(document.querySelectorAll('.pt-brand-strip'));
        strips.forEach(function (strip) {
            if (strip.dataset.sliderReady === '1') {
                return;
            }

            var logos = Array.prototype.slice.call(strip.children);
            if (logos.length < 2) {
                return;
            }

            logos.forEach(function (logo) {
                var clone = logo.cloneNode(true);
                clone.setAttribute('aria-hidden', 'true');
                clone.setAttribute('tabindex', '-1');
                strip.appendChild(clone);
            });

            strip.dataset.sliderReady = '1';
            strip.classList.add('is-slider');
        });
    });
}());

(function () {
    function ready(callback) {
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', callback, { once: true });
            return;
        }
        callback();
    }

    function escapeHtml(value) {
        return String(value || '').replace(/[&<>"']/g, function (character) {
            return {
                '&': '&amp;',
                '<': '&lt;',
                '>': '&gt;',
                '"': '&quot;',
                "'": '&#039;'
            }[character];
        });
    }

    ready(function () {
        var widgets = Array.prototype.slice.call(document.querySelectorAll('[data-product-search]'));
        if (!widgets.length) {
            return;
        }

        widgets.forEach(function (widget) {
            var toggle = widget.querySelector('[data-product-search-toggle]');
            var panel = widget.querySelector('[data-product-search-panel]');
            var form = widget.querySelector('[data-product-search-form]');
            var input = widget.querySelector('[data-product-search-input]');
            var status = widget.querySelector('[data-product-search-status]');
            var results = widget.querySelector('[data-product-search-results]');
            var timer = null;
            var controller = null;

            if (!toggle || !panel || !form || !input || !status || !results) {
                return;
            }

            function setOpen(open) {
                widget.classList.toggle('is-open', open);
                toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
                if (open) {
                    window.setTimeout(function () {
                        input.focus();
                    }, 40);
                }
            }

            function render(items) {
                if (!items.length) {
                    results.innerHTML = '<p class="pt-product-search__empty">Brak produktów dla tej frazy.</p>';
                    return;
                }

                results.innerHTML = items.map(function (item) {
                    var meta = [item.category, item.brand, item.sku].filter(Boolean).join(' · ');
                    return '<a class="pt-product-search__item" href="' + escapeHtml(item.url) + '">'
                        + (item.image ? '<img src="' + escapeHtml(item.image) + '" alt="">' : '<span class="pt-product-search__thumb"></span>')
                        + '<span><strong>' + escapeHtml(item.title) + '</strong>'
                        + (meta ? '<small>' + escapeHtml(meta) + '</small>' : '')
                        + (item.summary ? '<em>' + escapeHtml(item.summary).slice(0, 130) + '</em>' : '')
                        + '</span></a>';
                }).join('');
            }

            function search() {
                var query = input.value.trim();
                if (controller) {
                    controller.abort();
                }
                if (query.length < 2) {
                    status.textContent = 'Wpisz minimum 2 znaki.';
                    results.innerHTML = '';
                    return;
                }

                status.textContent = 'Szukam...';
                controller = new AbortController();
                fetch('/api/catalog/search?q=' + encodeURIComponent(query), {
                    headers: { 'Accept': 'application/json' },
                    signal: controller.signal
                })
                    .then(function (response) {
                        if (!response.ok) {
                            throw new Error('HTTP ' + response.status);
                        }
                        return response.json();
                    })
                    .then(function (data) {
                        var items = Array.isArray(data.items) ? data.items : [];
                        status.textContent = items.length ? 'Znaleziono: ' + items.length : 'Brak wyników';
                        render(items);
                    })
                    .catch(function (error) {
                        if (error.name === 'AbortError') {
                            return;
                        }
                        status.textContent = 'Nie udało się pobrać wyników.';
                        results.innerHTML = '';
                    });
            }

            toggle.addEventListener('click', function () {
                setOpen(!widget.classList.contains('is-open'));
            });

            input.addEventListener('input', function () {
                window.clearTimeout(timer);
                timer = window.setTimeout(search, 220);
            });

            form.addEventListener('submit', function (event) {
                var first = results.querySelector('a[href]');
                event.preventDefault();
                if (first) {
                    window.location.href = first.href;
                    return;
                }
                search();
            });

            document.addEventListener('click', function (event) {
                if (!widget.contains(event.target)) {
                    setOpen(false);
                }
            });

            document.addEventListener('keydown', function (event) {
                if (event.key === 'Escape') {
                    setOpen(false);
                }
            });
        });
    });
}());
