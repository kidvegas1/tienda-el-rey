(function () {
    'use strict';

    const LANG_KEY = 'el-rey-lang';

    function detectLang() {
        const saved = localStorage.getItem(LANG_KEY);
        if (saved === 'en' || saved === 'es') return saved;
        const nav = (navigator.language || 'es').toLowerCase();
        return nav.startsWith('en') ? 'en' : 'es';
    }

    let lang = detectLang();

    function t(key) {
        const pack = window.APP_I18N && window.APP_I18N[lang];
        if (pack && pack[key]) return pack[key];
        const fallback = window.APP_I18N && window.APP_I18N.es && window.APP_I18N.es[key];
        return fallback || key;
    }

    function applyI18n() {
        document.documentElement.lang = lang;
        document.querySelectorAll('[data-i18n]').forEach((el) => {
            const key = el.getAttribute('data-i18n');
            if (key) el.textContent = t(key);
        });
        document.querySelectorAll('[data-i18n-placeholder]').forEach((el) => {
            const key = el.getAttribute('data-i18n-placeholder');
            if (key) el.placeholder = t(key);
        });
        document.title = t('catalog.title');
        const meta = document.querySelector('meta[name="description"]');
        if (meta) meta.content = t('catalog.meta');
    }

    function esc(value) {
        return String(value ?? '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    function fmtMoney(value) {
        return new Intl.NumberFormat(lang === 'es' ? 'es-US' : 'en-US', {
            style: 'currency',
            currency: 'USD',
        }).format(Number(value));
    }

    const state = {
        stores: [],
        categories: [],
        products: [],
        selectedStore: '',
        selectedCategory: '',
        search: '',
        chatHistory: [],
        chatOpen: false,
    };

    const els = {};

    function cacheElements() {
        els.grid = document.getElementById('catalog-grid');
        els.storeSelect = document.getElementById('catalog-store');
        els.search = document.getElementById('catalog-search');
        els.chips = document.getElementById('catalog-chips');
        els.chatLog = document.getElementById('catalog-chat-log');
        els.chatForm = document.getElementById('catalog-chat-form');
        els.chatInput = document.getElementById('catalog-chat-input');
        els.chatSend = document.getElementById('catalog-chat-send');
        els.chatPanel = document.getElementById('catalog-chat-panel');
        els.chatBackdrop = document.getElementById('catalog-chat-backdrop');
        els.chatToggle = document.getElementById('catalog-chat-toggle');
        els.prompts = document.getElementById('catalog-chat-prompts');
    }

    async function fetchCatalog() {
        const params = new URLSearchParams();
        if (state.selectedStore) params.set('store_id', state.selectedStore);
        if (state.search) params.set('search', state.search);
        if (state.selectedCategory) params.set('category', state.selectedCategory);
        const res = await fetch('/api/catalog?' + params.toString(), {
            headers: { Accept: 'application/json' },
        });
        if (!res.ok) throw new Error('catalog fetch failed');
        return res.json();
    }

    function renderStores() {
        if (!els.storeSelect) return;
        const options = ['<option value="">' + esc(t('catalog.all_stores')) + '</option>'];
        state.stores.forEach((store) => {
            options.push('<option value="' + esc(String(store.id)) + '">' + esc(store.name) + '</option>');
        });
        els.storeSelect.innerHTML = options.join('');
        els.storeSelect.value = state.selectedStore;
    }

    function renderChips() {
        if (!els.chips) return;
        const chips = ['<button type="button" class="catalog-chip" data-category="" aria-pressed="' + (state.selectedCategory === '' ? 'true' : 'false') + '">' + esc(t('catalog.all_categories')) + '</button>'];
        state.categories.forEach((cat) => {
            chips.push('<button type="button" class="catalog-chip" data-category="' + esc(cat) + '" aria-pressed="' + (state.selectedCategory === cat ? 'true' : 'false') + '">' + esc(cat) + '</button>');
        });
        els.chips.innerHTML = chips.join('');
        els.chips.querySelectorAll('.catalog-chip').forEach((btn) => {
            btn.addEventListener('click', () => {
                state.selectedCategory = btn.dataset.category || '';
                renderChips();
                loadProducts();
            });
        });
    }

    function productCard(product) {
        const img = product.image_url
            ? '<img src="' + esc(product.image_url) + '" alt="" loading="lazy" decoding="async">'
            : '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="m7.5 4.27 9 5.15"/><path d="M21 8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16Z"/></svg>';
        const price = product.retail_price != null
            ? '<span class="catalog-price">' + esc(fmtMoney(product.retail_price)) + '</span>'
            : '<span class="catalog-price">' + esc(t('catalog.ask_price')) + '</span>';
        const desc = product.description ? '<p class="catalog-card-desc">' + esc(product.description) + '</p>' : '';
        return '<article class="catalog-card" id="product-' + esc(String(product.id)) + '">'
            + '<div class="catalog-card-media">' + img + '</div>'
            + '<div class="catalog-card-body">'
            + '<h3 class="catalog-card-title">' + esc(product.product_name) + '</h3>'
            + desc
            + '<div class="catalog-card-meta">'
            + '<span class="catalog-badge catalog-badge-store">' + esc(product.store_name) + '</span>'
            + '<span class="catalog-badge catalog-badge-stock">' + esc(t('catalog.available')) + '</span>'
            + price
            + '</div></div></article>';
    }

    function renderProducts() {
        if (!els.grid) return;
        if (!state.products.length) {
            els.grid.innerHTML = '<p class="catalog-empty">' + esc(t('catalog.empty')) + '</p>';
            return;
        }
        els.grid.innerHTML = state.products.map(productCard).join('');
    }

    async function loadProducts() {
        try {
            const data = await fetchCatalog();
            state.stores = data.stores || [];
            state.categories = data.categories || [];
            state.products = data.products || [];
            renderStores();
            renderChips();
            renderProducts();
        } catch (err) {
            if (els.grid) els.grid.innerHTML = '<p class="catalog-empty">' + esc(t('catalog.chat_error')) + '</p>';
        }
    }

    function appendMessage(role, html) {
        const div = document.createElement('div');
        div.className = 'catalog-msg ' + (role === 'user' ? 'catalog-msg-user' : 'catalog-msg-bot');
        div.innerHTML = html;
        els.chatLog.appendChild(div);
        els.chatLog.scrollTop = els.chatLog.scrollHeight;
    }

    function renderSuggested(products) {
        if (!products || !products.length) return '';
        const items = products.map((p) => {
            const img = p.image_url
                ? '<img src="' + esc(p.image_url) + '" alt="">'
                : '';
            return '<a class="catalog-suggested-item" href="#product-' + esc(String(p.id)) + '">'
                + img
                + '<span><strong>' + esc(p.product_name) + '</strong><br><small>' + esc(p.store_name) + '</small></span>'
                + '</a>';
        }).join('');
        return '<div class="catalog-suggested"><strong>' + esc(t('catalog.suggested')) + '</strong>' + items + '</div>';
    }

    async function sendChat(message) {
        const trimmed = (message || '').trim();
        if (!trimmed) return;
        appendMessage('user', esc(trimmed));
        state.chatHistory.push({ role: 'user', content: trimmed });
        els.chatInput.value = '';
        els.chatSend.disabled = true;
        appendMessage('assistant', esc(t('catalog.chat_thinking')));
        const thinking = els.chatLog.lastElementChild;

        try {
            const res = await fetch('/api/catalog-chat', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
                body: JSON.stringify({ message: trimmed, history: state.chatHistory.slice(-8) }),
            });
            const data = await res.json().catch(() => ({}));
            if (!res.ok) throw new Error(data.error || 'chat failed');
            thinking.remove();
            const replyHtml = esc(data.reply || '').replace(/\n/g, '<br>') + renderSuggested(data.suggested_products);
            appendMessage('assistant', replyHtml);
            state.chatHistory.push({ role: 'assistant', content: data.reply || '' });
        } catch (err) {
            thinking.textContent = t('catalog.chat_error');
        } finally {
            els.chatSend.disabled = false;
            els.chatInput.focus();
        }
    }

    function renderPrompts() {
        if (!els.prompts) return;
        const keys = ['catalog.chat_prompt_fever', 'catalog.chat_prompt_pain', 'catalog.chat_prompt_cold'];
        els.prompts.innerHTML = keys.map((key) =>
            '<button type="button" class="catalog-chat-prompt" data-prompt-key="' + esc(key) + '">' + esc(t(key)) + '</button>'
        ).join('');
        els.prompts.querySelectorAll('.catalog-chat-prompt').forEach((btn) => {
            btn.addEventListener('click', () => {
                const key = btn.getAttribute('data-prompt-key');
                if (key) sendChat(t(key));
            });
        });
    }

    function setChatOpen(open) {
        state.chatOpen = open;
        els.chatPanel?.classList.toggle('is-open', open);
        els.chatBackdrop?.classList.toggle('is-open', open);
    }

    function wireEvents() {
        els.search?.addEventListener('input', debounce(() => {
            state.search = els.search.value.trim();
            loadProducts();
        }, 280));

        els.storeSelect?.addEventListener('change', () => {
            state.selectedStore = els.storeSelect.value;
            loadProducts();
        });

        els.chatForm?.addEventListener('submit', (e) => {
            e.preventDefault();
            sendChat(els.chatInput.value);
        });

        els.chatToggle?.addEventListener('click', () => setChatOpen(true));
        els.chatBackdrop?.addEventListener('click', () => setChatOpen(false));

        document.querySelector('.menu-toggle')?.addEventListener('click', () => {
            const nav = document.getElementById('main-nav');
            const btn = document.querySelector('.menu-toggle');
            if (!nav || !btn) return;
            const open = nav.classList.toggle('is-open');
            btn.setAttribute('aria-expanded', open ? 'true' : 'false');
        });
    }

    function debounce(fn, wait) {
        let timer;
        return function debounced(...args) {
            clearTimeout(timer);
            timer = setTimeout(() => fn.apply(this, args), wait);
        };
    }

    document.addEventListener('DOMContentLoaded', () => {
        cacheElements();
        applyI18n();
        renderPrompts();
        wireEvents();
        loadProducts();
    });
})();
