const App = {
    csrf: '',
    user: null,
    stores: [],
    currentStore: null,
    lang: 'es',

    t(key, vars = {}) {
        const safeVars = vars && typeof vars === 'object' ? vars : {};
        const table = window.APP_I18N?.[this.lang] || window.APP_I18N?.en || {};
        let text = table[key] ?? window.APP_I18N?.en?.[key] ?? key;
        Object.keys(safeVars).forEach((k) => {
            text = text.replace(new RegExp(`\\{${k}\\}`, 'g'), String(safeVars[k]));
        });
        return text;
    },

    initLanguage() {
        const saved = localStorage.getItem('app_lang') || localStorage.getItem('suly_lang');
        this.lang = saved === 'en' ? 'en' : 'es';
        document.documentElement.lang = this.lang;
        this.applyI18n();
        this.initLanguageToggle();
    },

    setLanguage(lang) {
        const next = lang === 'es' ? 'es' : 'en';
        if (this.lang === next) return;
        this.lang = next;
        localStorage.setItem('app_lang', next);
        document.documentElement.lang = next;
        this.applyI18n();
        this.updateUserMenu();
        window.dispatchEvent(new CustomEvent('language-changed', { detail: { lang: next } }));
        this.toast(next === 'es' ? this.t('lang.switch_to_es') : this.t('lang.switch_to_en'), 'info');
    },

    applyI18n() {
        document.querySelectorAll('[data-i18n]').forEach((el) => {
            const key = el.getAttribute('data-i18n');
            if (!key) return;
            const text = this.t(key);
            el.textContent = text;
        });

        document.querySelectorAll('[data-i18n-placeholder]').forEach((el) => {
            const key = el.getAttribute('data-i18n-placeholder');
            if (key) el.placeholder = this.t(key);
        });

        document.querySelectorAll('[data-i18n-title]').forEach((el) => {
            const key = el.getAttribute('data-i18n-title');
            if (key) el.title = this.t(key);
        });

        document.querySelectorAll('[data-i18n-alt]').forEach((el) => {
            const key = el.getAttribute('data-i18n-alt');
            if (key) el.alt = this.t(key);
        });

        document.querySelectorAll('.sidebar-link').forEach((link) => {
            const href = link.getAttribute('href');
            const key = window.APP_NAV_I18N?.[href];
            if (!key) return;
            Array.from(link.childNodes).forEach((node) => {
                if (node.nodeType === Node.TEXT_NODE && node.textContent.trim()) {
                    node.remove();
                }
            });
            let label = link.querySelector('.nav-label');
            if (!label) {
                label = document.createElement('span');
                label.className = 'nav-label';
                link.appendChild(label);
            }
            label.textContent = this.t(key);
        });

        const page = this.pageRouteKey();
        const pageKeys = window.APP_PAGE_I18N?.[page];
        if (pageKeys?.title) {
            document.title = this.t(pageKeys.title);
        }
        if (pageKeys?.heading) {
            const h = document.getElementById('page-title');
            if (h) h.textContent = this.t(pageKeys.heading);
        }
        const pageSub = document.getElementById('page-subtitle');
        if (pageSub && pageKeys?.subtitle) pageSub.textContent = this.t(pageKeys.subtitle);

        this.applySidebarBranding();

        const headerH2 = document.querySelector('header h2.text-xl');
        if (headerH2 && pageKeys?.heading && !document.getElementById('page-title')) {
            headerH2.textContent = this.t(pageKeys.heading);
        }

        const langBtn = document.getElementById('lang-toggle');
        if (langBtn) {
            langBtn.textContent = this.lang === 'es' ? 'EN' : 'ES';
            langBtn.title = this.lang === 'es' ? this.t('lang.switch_to_en') : this.t('lang.switch_to_es');
            langBtn.setAttribute('aria-label', this.t('lang.toggle'));
        }

        this.stampMobileCardLabels();
    },

    // ponytail: CSS cards from existing tables; stamp labels from thead
    stampMobileCardLabels(root = document) {
        root.querySelectorAll('.table-container.mobile-cards table, .overflow-x-auto.mobile-cards table').forEach((table) => {
            const labels = [...table.querySelectorAll('thead th')].map((th) => th.textContent.trim());
            table.querySelectorAll('tbody tr').forEach((tr) => {
                [...tr.children].forEach((td, i) => {
                    const label = labels[i] || '';
                    if (!label || td.querySelector('button, a.btn-primary, a.btn-secondary, .btn-primary, .btn-secondary')) {
                        td.removeAttribute('data-label');
                        return;
                    }
                    td.setAttribute('data-label', label);
                });
            });
        });
    },

    initMobileCards() {
        this.stampMobileCardLabels();
        if (this._mobileCardsReady) return;
        this._mobileCardsReady = true;
        // ponytail: observe re-renders so pages need only add class="mobile-cards"
        const obs = new MutationObserver(() => {
            clearTimeout(this._mobileCardsTimer);
            this._mobileCardsTimer = setTimeout(() => this.stampMobileCardLabels(), 40);
        });
        document.querySelectorAll('.table-container.mobile-cards, .overflow-x-auto.mobile-cards').forEach((el) => {
            obs.observe(el, { childList: true, subtree: true });
        });
        this._mobileCardsObserver = obs;
    },

    // ponytail: shared info-tip hover (CSS already in app.css)
    bindInfoTips(root = document) {
        const hide = () => {
            if (this._floatingTip) {
                this._floatingTip.remove();
                this._floatingTip = null;
            }
        };
        const show = (btn) => {
            hide();
            const key = btn.getAttribute('data-tip-key') || '';
            const text = key ? this.t(key) : '';
            if (!text || text === key) return;
            const tip = document.createElement('div');
            tip.className = 'info-tip-float';
            tip.setAttribute('role', 'tooltip');
            tip.textContent = text;
            document.body.appendChild(tip);
            const rect = btn.getBoundingClientRect();
            const tipRect = tip.getBoundingClientRect();
            let left = Math.min(Math.max(8, rect.right - tipRect.width), window.innerWidth - tipRect.width - 8);
            let top = rect.bottom + 8;
            if (top + tipRect.height > window.innerHeight - 8) {
                top = Math.max(8, rect.top - tipRect.height - 8);
            }
            tip.style.left = left + 'px';
            tip.style.top = top + 'px';
            this._floatingTip = tip;
        };
        root.querySelectorAll('[data-info-tip]').forEach((btn) => {
            if (btn.dataset.tipBound) return;
            btn.dataset.tipBound = '1';
            if (!btn.querySelector('svg')) {
                btn.innerHTML = `<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"/><path d="M12 16v-4"/><path d="M12 8h.01"/></svg>`;
            }
            btn.addEventListener('mouseenter', () => show(btn));
            btn.addEventListener('mouseleave', hide);
            btn.addEventListener('focus', () => show(btn));
            btn.addEventListener('blur', hide);
            btn.addEventListener('click', (e) => { e.preventDefault(); e.stopPropagation(); });
        });
    },

    pageRouteKey() {
        const path = location.pathname.replace(/\/$/, '');
        if (path === '' || path === '/' || path.endsWith('/login') || path === '/login') return 'login';
        return this.currentRouteKey();
    },

    currentRouteKey() {
        const path = window.location.pathname.split('/').pop().replace('.html', '');
        return path === 'index' ? 'dashboard' : path;
    },

    applySidebarBranding() {
        const header = document.querySelector('#sidebar .border-b.border-border');
        if (!header) return;
        const h1 = header.querySelector('h1');
        if (h1) h1.textContent = this.t('app.name');
        const sub = header.querySelector('p.text-xs');
        if (sub) {
            const route = this.currentRouteKey();
            const nav = window.APP_NAV_I18N || {};
            sub.textContent = (route === '' || route === 'dashboard')
                ? this.t('app.subtitle')
                : this.t(nav[route] || 'app.subtitle');
        }
    },

    initLanguageToggle() {
        if (document.getElementById('lang-toggle')) return;

        const btn = document.createElement('button');
        btn.type = 'button';
        btn.id = 'lang-toggle';
        btn.className = 'lang-toggle-btn';
        btn.addEventListener('click', () => this.setLanguage(this.lang === 'es' ? 'en' : 'es'));

        const sidebarFooter = document.querySelector('#sidebar .p-4.border-t');
        if (sidebarFooter) {
            const wrap = document.createElement('div');
            wrap.className = 'px-1 pb-3';
            wrap.innerHTML = `<p class="text-[10px] font-semibold text-text-muted uppercase tracking-wider mb-2 px-2" data-i18n="lang.toggle"></p>`;
            wrap.appendChild(btn);
            sidebarFooter.parentNode.insertBefore(wrap, sidebarFooter);
            this.applyI18n();
            return;
        }

        const loginCard = document.querySelector('#login-form');
        if (loginCard) {
            const wrap = document.createElement('div');
            wrap.className = 'flex justify-center mb-4';
            wrap.appendChild(btn);
            loginCard.parentNode.insertBefore(wrap, loginCard);
            this.applyI18n();
        }
    },

    async init() {
        this.initLanguage();
        window.t = (key, vars) => this.t(key, vars);
        const res = await this.api('GET', '/api/auth');
        if (!res.authenticated) {
            if (!location.pathname.endsWith('/login') && location.pathname !== '/') {
                location.href = 'login';
            }
            return false;
        }
        this.user = res.user;
        this.csrf = res.csrf;
        this.stores = res.stores;
        const sessionStoreId = Number(res.user.store_id) || 0;
        this.currentStore = res.stores.find(s => s.id == sessionStoreId) || res.stores[0];
        if (
            this.user?.role === 'admin'
            && this.currentStore
            && sessionStoreId <= 0
        ) {
            const switched = await this.api('POST', '/api/auth', {
                action: 'switch_store',
                store_id: this.currentStore.id,
            });
            this.user.store_id = switched.store_id ?? this.currentStore.id;
        }
        this.initMobileCards();
        return true;
    },

    async api(method, url, body = null) {
        const opts = {
            method,
            headers: { 'Content-Type': 'application/json' },
            credentials: 'same-origin',
        };
        if (this.csrf) {
            opts.headers['X-CSRF-Token'] = this.csrf;
        }
        if (body) {
            opts.body = JSON.stringify(body);
        }
        const res = await fetch(url, opts);
        const data = await res.json();
        if (!res.ok) {
            throw new Error(data.error || `HTTP ${res.status}`);
        }
        return data;
    },

    async apiForm(url, formData) {
        const opts = {
            method: 'POST',
            body: formData,
            credentials: 'same-origin',
            headers: {},
        };
        if (this.csrf) {
            opts.headers['X-CSRF-Token'] = this.csrf;
        }
        const res = await fetch(url, opts);
        let data = {};
        const raw = await res.text();
        if (raw) {
            try {
                data = JSON.parse(raw);
            } catch (e) {
                if (!res.ok) {
                    throw new Error(raw.slice(0, 200) || `HTTP ${res.status}`);
                }
            }
        }
        if (!res.ok) {
            throw new Error(data.error || raw.slice(0, 200) || `HTTP ${res.status}`);
        }
        return data;
    },

    toastKey(key, type = 'info', vars = null) {
        this.toast(this.t(key, vars || {}), type);
    },

    confirmKey(key, vars = null) {
        return confirm(this.t(key, vars || {}));
    },

    toast(message, type = 'info') {
        const container = document.getElementById('toast-container') || this._createToastContainer();
        const toast = document.createElement('div');
        toast.className = 'toast';
        const colors = {
            success: 'border-l-4 border-l-[var(--color-success)]',
            danger: 'border-l-4 border-l-[var(--color-danger)]',
            warning: 'border-l-4 border-l-[var(--color-warning)]',
            info: 'border-l-4 border-l-[var(--color-accent)]',
        };
        toast.classList.add(...(colors[type] || colors.info).split(' '));
        toast.textContent = message;
        container.appendChild(toast);
        requestAnimationFrame(() => toast.classList.add('show'));
        setTimeout(() => {
            toast.classList.remove('show');
            setTimeout(() => toast.remove(), 300);
        }, 3500);
    },

    _createToastContainer() {
        const c = document.createElement('div');
        c.id = 'toast-container';
        c.className = 'toast-container';
        document.body.appendChild(c);
        return c;
    },

    initSidebar() {
        const toggle = document.getElementById('sidebar-toggle');
        const sidebar = document.getElementById('sidebar');
        const overlay = document.getElementById('sidebar-overlay');
        if (toggle && sidebar) {
            if (!toggle.getAttribute('aria-label')) {
                toggle.setAttribute('aria-label', this.t('action.open_menu'));
            }
            if (!toggle.getAttribute('type')) {
                toggle.setAttribute('type', 'button');
            }
            toggle.addEventListener('click', () => {
                sidebar.classList.toggle('open');
                overlay?.classList.toggle('open');
            });
            overlay?.addEventListener('click', () => {
                sidebar.classList.remove('open');
                overlay.classList.remove('open');
            });
        }
        const current = location.pathname.split('/').pop() || 'dashboard';
        document.querySelectorAll('.sidebar-link').forEach(link => {
            const href = link.getAttribute('href');
            const isActive = href === current || (current === '' && href === 'dashboard');
            link.classList.toggle('active', isActive);
            // Reset any prior role-based hiding so navigation stays stable across pages.
            link.style.display = '';
        });
        this.applyRoleNav();
    },

    /** Hide admin-only nav entries and redirect if role cannot open the page. */
    applyRoleNav() {
        const role = this.user?.role;
        const page = location.pathname.split('/').pop() || 'dashboard';
        if (role === 'admin') return;
        // Keep in sync with includes/auth.php auth_admin_only_paths() + reports-center.
        const adminOnly = ['stores', 'employees', 'analytics', 'metas', 'security', 'reports-center'];
        const managerPlus = ['import', 'sales-log'];
        document.querySelectorAll('.sidebar-link').forEach(link => {
            const href = link.getAttribute('href');
            if (adminOnly.includes(href)) {
                link.style.display = 'none';
            }
            if (managerPlus.includes(href) && role !== 'manager') {
                link.style.display = 'none';
            }
        });
        if (adminOnly.includes(page) || (managerPlus.includes(page) && role !== 'manager')) {
            location.href = 'dashboard';
        }
    },

    initStoreSelector() {
        const sels = document.querySelectorAll('select#store-selector, select#store-selector-sidebar');
        if (!sels.length) return;
        const isAdmin = this.user?.role === 'admin';
        sels.forEach(sel => {
            sel.innerHTML = this.stores.map(s =>
                `<option value="${s.id}" ${s.id == this.currentStore?.id ? 'selected' : ''}>${s.name}</option>`
            ).join('');
        });
        if (!isAdmin) {
            sels.forEach(sel => {
                sel.disabled = true;
                sel.title = this.t('store.locked');
                sel.classList.add('hidden');
            });
            return;
        }
        sels.forEach(sel => {
            sel.addEventListener('change', async (e) => {
                try {
                    await this.api('POST', '/api/auth', { action: 'switch_store', store_id: parseInt(e.target.value) });
                    this.currentStore = this.stores.find(s => s.id == e.target.value);
                    this.toast(this.t('store.switched', { name: this.currentStore.name }), 'success');
                    window.dispatchEvent(new CustomEvent('store-changed'));
                } catch (err) {
                    this.toast(err.message, 'danger');
                }
            });
        });
    },

    async logout() {
        try {
            await this.api('POST', '/api/auth', { action: 'logout' });
        } catch (_) { /* session may already be cleared */ }
        location.href = 'login';
    },

    updateUserMenu() {
        const nameEl = document.getElementById('user-name');
        const roleEl = document.getElementById('user-role');
        const employee = this.user?.employee;
        if (nameEl) {
            nameEl.textContent = employee?.name || this.user?.name || '';
        }
        if (roleEl) {
            const role = this.user?.role || '';
            const roleLabel = role ? this.t(`role.${role}`) : '';
            const details = [roleLabel || role];
            if (employee?.phone) details.push(employee.phone);
            roleEl.textContent = details.join(' · ');
        }

        const logoutBtn = document.getElementById('logout-btn');
        if (logoutBtn) logoutBtn.title = this.t('action.sign_out');
    },

    initUserMenu() {
        this.updateUserMenu();

        const logoutBtn = document.getElementById('logout-btn');
        if (logoutBtn && !logoutBtn.dataset.bound) {
            logoutBtn.dataset.bound = '1';
            logoutBtn.addEventListener('click', () => this.logout());
        }
    },

    openModal(id) {
        document.getElementById(id)?.classList.add('open');
        document.getElementById(id + '-backdrop')?.classList.add('open');
        document.body.classList.add('modal-open');
    },

    closeModal(id) {
        document.getElementById(id)?.classList.remove('open');
        document.getElementById(id + '-backdrop')?.classList.remove('open');
        const anyOpen = document.querySelector('.modal-content.open');
        if (!anyOpen) document.body.classList.remove('modal-open');
    },

    money(val) {
        const locale = this.lang === 'es' ? 'es-US' : 'en-US';
        const n = Number(val) || 0;
        if (n < 0) {
            return `(${new Intl.NumberFormat(locale, { style: 'currency', currency: 'USD' }).format(Math.abs(n))})`;
        }
        return new Intl.NumberFormat(locale, { style: 'currency', currency: 'USD' }).format(n);
    },

    /** Dashboard-friendly currency: minus sign (not parentheses) and optional compact notation. */
    moneyMetric(val, { compact = 'auto' } = {}) {
        const locale = this.lang === 'es' ? 'es-US' : 'en-US';
        const n = Number(val) || 0;
        const abs = Math.abs(n);
        const useCompact = compact === true || (compact === 'auto' && abs >= 100000);
        if (useCompact) {
            return new Intl.NumberFormat(locale, {
                style: 'currency',
                currency: 'USD',
                notation: 'compact',
                maximumFractionDigits: abs >= 1000000 ? 2 : 1,
            }).format(n);
        }
        return new Intl.NumberFormat(locale, {
            style: 'currency',
            currency: 'USD',
            minimumFractionDigits: 2,
            maximumFractionDigits: 2,
        }).format(n);
    },

    /** Full currency for tooltips (minus sign, not parentheses). */
    moneyFull(val) {
        const locale = this.lang === 'es' ? 'es-US' : 'en-US';
        const n = Number(val) || 0;
        return new Intl.NumberFormat(locale, {
            style: 'currency',
            currency: 'USD',
            minimumFractionDigits: 2,
            maximumFractionDigits: 2,
        }).format(n);
    },

    applyMetric(el, displayText, fullText = null) {
        if (!el) return;
        el.textContent = displayText;
        el.title = fullText && fullText !== displayText ? fullText : '';
        el.classList.remove('metric-value--xs', 'metric-value--sm', 'metric-value--md', 'metric-value--lg');
        const len = String(displayText).length;
        if (len >= 14) el.classList.add('metric-value--xs');
        else if (len >= 11) el.classList.add('metric-value--sm');
        else if (len >= 8) el.classList.add('metric-value--md');
        else el.classList.add('metric-value--lg');
    },

    setMoneyMetric(el, val, options = {}) {
        const full = this.moneyFull(val);
        const display = this.moneyMetric(val, options);
        this.applyMetric(el, display, full);
        el.classList.toggle('text-danger', Number(val) < 0);
    },

    setCountMetric(el, val) {
        const locale = this.lang === 'es' ? 'es-US' : 'en-US';
        this.applyMetric(el, Number(val || 0).toLocaleString(locale));
    },

    formatDate(str) {
        if (!str) return this.t('empty.none');
        const locale = this.lang === 'es' ? 'es-US' : 'en-US';
        return new Date(str).toLocaleDateString(locale, { month: 'short', day: 'numeric', year: 'numeric' });
    },

    formatDateTime(str) {
        if (!str) return this.t('empty.none');
        const locale = this.lang === 'es' ? 'es-US' : 'en-US';
        return new Date(str).toLocaleString(locale, { month: 'short', day: 'numeric', year: 'numeric', hour: 'numeric', minute: '2-digit' });
    },

    debounce(fn, ms = 300) {
        let timer;
        return (...args) => {
            clearTimeout(timer);
            timer = setTimeout(() => fn(...args), ms);
        };
    },
};
