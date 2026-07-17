(function () {
    'use strict';

    var STORAGE_KEY = 'reklamova_privacy_consent';
    var COOKIE_NAME = 'reklamova_privacy_consent';
    var SESSION_PREVIEW_DISMISSED_KEY = 'reklamova_privacy_preview_dismissed';
    var config = readConfig();
    var settings = null;
    var loadedScripts = {};
    var state = readState();

    function readConfig() {
        var node = document.getElementById('reklamova-privacy-config');
        if (!node) {
            return {};
        }
        try {
            return JSON.parse(node.textContent || '{}');
        } catch (error) {
            return {};
        }
    }

    function emit(name, detail) {
        window.dispatchEvent(new CustomEvent(name, { detail: detail || {} }));
    }

    function readState() {
        return normalizeState(readLocalState()) || normalizeState(readCookieState());
    }

    function readLocalState() {
        try {
            var value = localStorage.getItem(STORAGE_KEY);
            return value ? JSON.parse(value) : null;
        } catch (error) {
            return null;
        }
    }

    function normalizeState(value) {
        if (!value || typeof value !== 'object' || !value.categories || typeof value.categories !== 'object' || !value.consentVersion) {
            return null;
        }

        return value;
    }

    function chooseStoredStateForCurrentVersion() {
        var localState = normalizeState(readLocalState());
        var cookieState = normalizeState(readCookieState());
        var currentVersion = settings && settings.banner ? String(settings.banner.consentVersion) : '';
        if (!currentVersion) {
            return localState || cookieState;
        }
        if (localState && String(localState.consentVersion) === currentVersion) {
            return localState;
        }
        if (cookieState && String(cookieState.consentVersion) === currentVersion) {
            try {
                localStorage.setItem(STORAGE_KEY, JSON.stringify(cookieState));
            } catch (error) {}
            return cookieState;
        }
        return localState || cookieState;
    }

    function readCookieState() {
        var prefix = COOKIE_NAME + '=';
        var items = (document.cookie || '').split(';');
        for (var index = 0; index < items.length; index++) {
            var item = items[index].trim();
            if (item.indexOf(prefix) !== 0) {
                continue;
            }

            try {
                var decoded = decodeURIComponent(item.slice(prefix.length));
                return normalizeState(JSON.parse(decoded));
            } catch (error) {
                return null;
            }
        }

        return null;
    }

    function writeState(nextState) {
        state = nextState;
        try {
            localStorage.setItem(STORAGE_KEY, JSON.stringify(nextState));
        } catch (error) {}

        var cookieState = {
            consentUuid: nextState.consentUuid,
            consentVersion: nextState.consentVersion,
            categories: nextState.categories,
            updatedAt: nextState.updatedAt
        };
        var secure = window.location.protocol === 'https:' ? '; Secure' : '';
        document.cookie = COOKIE_NAME + '=' + encodeURIComponent(JSON.stringify(cookieState)) + '; path=/; max-age=' + String((settings && settings.banner ? settings.banner.ttlDays : 365) * 86400) + '; SameSite=Lax' + secure;
    }

    function rememberAdminPreviewDismissed() {
        if (!settings || !settings.banner || !settings.banner.showAlwaysForAdmins) {
            return;
        }
        try {
            sessionStorage.setItem(SESSION_PREVIEW_DISMISSED_KEY, String(settings.banner.consentVersion || '1'));
        } catch (error) {}
    }

    function wasAdminPreviewDismissed() {
        if (!settings || !settings.banner || !settings.banner.showAlwaysForAdmins) {
            return false;
        }
        try {
            return sessionStorage.getItem(SESSION_PREVIEW_DISMISSED_KEY) === String(settings.banner.consentVersion || '1');
        } catch (error) {
            return false;
        }
    }

    function uuid() {
        if (window.crypto && crypto.randomUUID) {
            return crypto.randomUUID();
        }
        return 'consent-' + Date.now() + '-' + Math.random().toString(16).slice(2);
    }

    function defaultCategories(grantAll) {
        var result = {};
        (settings.categories || []).forEach(function (category) {
            result[category.slug] = category.required || !!grantAll;
        });
        return result;
    }

    function consentModeFromCategories(categories) {
        var consent = Object.assign({}, window.ReklamovaConsentModeDefault || {
            ad_storage: 'denied',
            ad_user_data: 'denied',
            ad_personalization: 'denied',
            analytics_storage: 'denied',
            functionality_storage: 'denied',
            personalization_storage: 'denied',
            security_storage: 'granted'
        });
        (settings.categories || []).forEach(function (category) {
            var granted = category.required || !!categories[category.slug];
            Object.keys(category.consentMode || {}).forEach(function (key) {
                consent[key] = granted ? 'granted' : 'denied';
            });
        });
        consent.security_storage = 'granted';
        return consent;
    }

    function updateConsentMode(categories) {
        var consent = consentModeFromCategories(categories);
        if (typeof window.gtag === 'function') {
            window.gtag('consent', 'update', consent);
        }
        return consent;
    }

    function persistDecision(categories, source) {
        var nextState = {
            consentUuid: state && state.consentUuid ? state.consentUuid : uuid(),
            consentVersion: settings.banner.consentVersion,
            categories: categories,
            state: updateConsentMode(categories),
            source: source || 'manual',
            updatedAt: new Date().toISOString()
        };
        writeState(nextState);
        rememberAdminPreviewDismissed();
        postConsent(nextState);
        loadAllowedScripts();
        emit('reklamovaConsentUpdated', nextState);
    }

    function postConsent(nextState) {
        if (!settings || !settings.banner || !config.consentEndpoint || !navigator.sendBeacon && !window.fetch) {
            return;
        }
        var payload = JSON.stringify({
            consentUuid: nextState.consentUuid,
            consentVersion: nextState.consentVersion,
            categories: nextState.categories,
            state: nextState.state,
            pageUrl: window.location.href
        });
        if (navigator.sendBeacon) {
            navigator.sendBeacon(config.consentEndpoint, new Blob([payload], { type: 'application/json' }));
            return;
        }
        fetch(config.consentEndpoint, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: payload, credentials: 'same-origin' }).catch(function () {});
    }

    function hasCurrentDecision() {
        return !!(state && settings && String(state.consentVersion) === String(settings.banner.consentVersion) && state.categories);
    }

    function shouldShowBanner(payload) {
        if (!hasCurrentDecision()) {
            return true;
        }

        return !!(payload.banner.showAlwaysForAdmins && !wasAdminPreviewDismissed());
    }

    function hasConsent(category) {
        if (!settings) {
            return category === 'necessary';
        }
        var found = (settings.categories || []).filter(function (item) { return item.slug === category; })[0];
        return !!(found && found.required) || !!(state && state.categories && state.categories[category]);
    }

    function loadAllowedScripts() {
        (config.scripts || []).forEach(function (script) {
            if (loadedScripts[script.id] || !hasConsent(script.category)) {
                if (config.debug) {
                    console.debug('[Reklamova Privacy] blocked script', script.name, script.category);
                }
                return;
            }
            loadedScripts[script.id] = true;
            loadScript(script);
            emit('reklamovaConsentCategoryGranted', { category: script.category, script: script });
        });
    }

    function loadScript(script) {
        if (script.externalUrl) {
            var external = document.createElement('script');
            external.src = script.externalUrl;
            external.async = !!script.async;
            external.defer = !!script.defer;
            external.dataset.reklamovaPrivacyScript = String(script.id);
            targetFor(script.placement).appendChild(external);
        }
        if (script.code) {
            var inline = document.createElement('script');
            inline.text = script.code;
            inline.dataset.reklamovaPrivacyScript = String(script.id);
            targetFor(script.placement).appendChild(inline);
        }
        if (config.debug) {
            console.debug('[Reklamova Privacy] loaded script', script.name, script.category);
        }
    }

    function targetFor(placement) {
        if (placement === 'head') {
            return document.head;
        }
        return document.body || document.documentElement;
    }

    function openSettings() {
        if (!settings) {
            return;
        }
        renderModal(true);
    }

    function closeModal() {
        var modal = document.querySelector('[data-reklamova-privacy-modal]');
        if (modal) {
            modal.remove();
        }
    }

    function renderModal(forcePreferences) {
        closeModal();
        var categories = Object.assign(defaultCategories(false), state && state.categories ? state.categories : {});
        var overlay = document.createElement('div');
        overlay.className = 'rk-privacy rk-privacy--' + (settings.banner.style || 'minimal') + ' rk-privacy--' + (settings.banner.mode || 'bottom_bar');
        overlay.dataset.reklamovaPrivacyModal = 'true';
        overlay.innerHTML = [
            '<section class="rk-privacy__panel" role="dialog" aria-modal="true" aria-labelledby="rk-privacy-title">',
            '<div class="rk-privacy__header"><span>Reklamova Privacy Center</span><button type="button" class="rk-privacy__close" data-action="close" aria-label="Zamknij">x</button></div>',
            '<h2 id="rk-privacy-title">' + escapeHtml(settings.banner.title || 'Prywatność i cookies') + '</h2>',
            '<p>' + escapeHtml(settings.banner.text || '') + '</p>',
            '<div class="rk-privacy__categories"' + (forcePreferences ? '' : ' hidden') + '>' + categoryControls(categories) + '</div>',
            '<div class="rk-privacy__actions">',
            '<button type="button" data-action="reject">' + escapeHtml(settings.banner.buttons.rejectAll || 'Odrzucam') + '</button>',
            '<button type="button" data-action="customize">' + escapeHtml(settings.banner.buttons.customize || 'Dostosuj') + '</button>',
            '<button type="button" data-action="accept" class="rk-privacy__primary">' + escapeHtml(settings.banner.buttons.acceptAll || 'Akceptuję wszystko') + '</button>',
            '<button type="button" data-action="save" class="rk-privacy__primary" ' + (forcePreferences ? '' : 'hidden') + '>' + escapeHtml(settings.banner.buttons.save || 'Zapisz wybór') + '</button>',
            '</div>',
            '</section>'
        ].join('');

        overlay.addEventListener('click', function (event) {
            var action = event.target && event.target.getAttribute('data-action');
            if (!action) {
                return;
            }
            if (action === 'close') {
                if (hasCurrentDecision()) {
                    rememberAdminPreviewDismissed();
                }
                closeModal();
            }
            if (action === 'customize') {
                overlay.querySelector('.rk-privacy__categories').hidden = false;
                overlay.querySelector('[data-action="save"]').hidden = false;
            }
            if (action === 'accept') {
                persistDecision(defaultCategories(true), 'accept_all');
                closeModal();
            }
            if (action === 'reject') {
                persistDecision(defaultCategories(false), 'reject_all');
                closeModal();
            }
            if (action === 'save') {
                var chosen = {};
                overlay.querySelectorAll('input[data-category]').forEach(function (input) {
                    chosen[input.dataset.category] = input.checked;
                });
                persistDecision(chosen, 'custom');
                closeModal();
            }
        });

        document.body.appendChild(overlay);
    }

    function categoryControls(categories) {
        return (settings.categories || []).map(function (category) {
            var checked = category.required || !!categories[category.slug];
            return '<label class="rk-privacy__category"><span><strong>' + escapeHtml(category.name) + '</strong><small>' + escapeHtml(category.shortDescription || '') + '</small></span><input type="checkbox" data-category="' + escapeHtml(category.slug) + '"' + (checked ? ' checked' : '') + (category.required ? ' disabled' : '') + '></label>';
        }).join('');
    }

    function escapeHtml(value) {
        return String(value).replace(/[&<>"']/g, function (char) {
            return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' })[char];
        });
    }

    function bindOpenLinks() {
        document.addEventListener('click', function (event) {
            var trigger = event.target.closest && event.target.closest('[data-reklamova-privacy-open]');
            if (!trigger) {
                return;
            }
            event.preventDefault();
            openSettings();
        });
    }

    function boot() {
        bindOpenLinks();
        fetch(config.settingsEndpoint || '/api/privacy/settings', { credentials: 'same-origin' })
            .then(function (response) { return response.json(); })
            .then(function (payload) {
                settings = payload;
                state = chooseStoredStateForCurrentVersion();
                if (state && state.categories) {
                    updateConsentMode(state.categories);
                }
                if (shouldShowBanner(payload)) {
                    renderModal(false);
                } else {
                    loadAllowedScripts();
                }
                emit('reklamovaConsentReady', { settings: settings, state: state });
            })
            .catch(function () {
                emit('reklamovaConsentReady', { settings: null, state: state, error: true });
            });
    }

    window.ReklamovaConsent = {
        openSettings: openSettings,
        getState: function () { return state; },
        hasConsent: hasConsent,
        updateConsent: function (categories) { persistDecision(categories, 'api'); }
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
})();
