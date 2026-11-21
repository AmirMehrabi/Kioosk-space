import Alpine from 'alpinejs';

const icon = (paths) => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' + paths + '</svg>';
const icons = {
    brand: icon('<rect x="3" y="5" width="18" height="14" rx="3"/><path d="m4 7 8 6 8-6M8 16h8"/>'),
    inbox: icon('<path d="M4 4h16l2 10v4a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2v-4L4 4Z"/><path d="M2 14h6l2 3h4l2-3h6"/>'),
    feed: icon('<rect x="4" y="4" width="16" height="16" rx="3"/><path d="M8 9h8m-8 4h8m-8 4h4"/>'),
    receipt: icon('<path d="M6 3l3 2 3-2 3 2 3-2v18l-3-2-3 2-3-2-3 2V3Z"/><path d="M9 10h6m-6 4h5"/>'),
    clock: icon('<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>'),
    bookmark: icon('<path d="M6 5a2 2 0 0 1 2-2h8a2 2 0 0 1 2 2v16l-6-4-6 4V5Z"/>'),
    send: icon('<path d="m22 2-7 20-4-9-9-4 20-7ZM22 2 11 13"/>'),
    edit: icon('<path d="m14 5 5 5M4 20l5-1L20 8a3.5 3.5 0 0 0-5-5L4 14l-1 6Z"/>'),
    search: icon('<circle cx="10.5" cy="10.5" r="6.5"/><path d="m16 16 4 4"/>'),
    more: icon('<circle cx="5" cy="12" r="1"/><circle cx="12" cy="12" r="1"/><circle cx="19" cy="12" r="1"/>'),
    reply: icon('<path d="m14 5 6 6-6 6v-4h-3c-4 0-6 2-7 5v-2a7 7 0 0 1 7-7h3V5Z"/>'),
    chevron: icon('<path d="m6 9 6 6 6-6"/>'),
    back: icon('<path d="m13 6 6 6-6 6m6-6H5"/>'),
};

const folders = {
    imbox: { title: 'نامه‌های مهم', icon: 'inbox', description: 'نامه‌هایی که می‌خواهید زودتر ببینید.' },
    feed: { title: 'خواندنی‌ها', icon: 'feed', description: 'خبرنامه‌ها و مطالبی برای وقت آزاد.' },
    receipts: { title: 'رسیدها', icon: 'receipt', description: 'رسیدها و تأییدیه‌ها برای مراجعهٔ بعدی.' },
    later: { title: 'پاسخ بعداً', icon: 'clock', description: 'نامه‌هایی که می‌خواهید بعداً پاسخ دهید.' },
    saved: { title: 'کنارگذاشته‌ها', icon: 'bookmark', description: 'نامه‌هایی که می‌خواهید دم دست باشند.' },
    sent: { title: 'ارسال‌شده‌ها', icon: 'send', description: 'نامه‌هایی که در نسخهٔ آزمایشی فرستاده‌اید.' },
};

const emptyDraft = () => ({ to: '', subject: '', body: '' });
const number = new Intl.NumberFormat('fa-IR');
const time = new Intl.DateTimeFormat('fa-IR', { month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' });

async function mailRequest(path, method = 'GET', payload) {
    const response = await fetch('/mail/' + path, {
        method,
        headers: {
            'Accept': 'application/json',
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
        },
        body: payload === undefined ? undefined : JSON.stringify(payload),
    });
    const data = await response.json();
    if (!response.ok) throw new Error(response.status === 401 || response.status === 419
        ? 'برای ادامه دوباره وارد حساب شوید.'
        : Object.values(data.errors ?? {})[0]?.[0] ?? data.message ?? 'ارتباط با صندوق برقرار نشد.');
    return data;
}

Alpine.data('mailbox', () => ({
    icons,
    folders,
    get accountEmail() {
        return this.$root.dataset.accountEmail;
    },
    get accountName() {
        return this.$root.dataset.accountName;
    },
    get accountInitial() {
        return this.$root.dataset.accountInitial;
    },
    messages: [],
    draft: emptyDraft(),
    get compose() {
        return this.$root.dataset.composing === 'true';
    },
    get composeUrl() {
        return this.$root.dataset.composeUrl;
    },
    folderKey: 'imbox',
    query: '',
    selected: null,
    headerMenu: null,
    notice: '',
    noticeTimer: null,
    loading: false,
    busy: false,
    loadError: '',
    pages: {},
    loadVersion: 0,
    draftTimer: null,
    searchTimer: null,
    draftWrite: Promise.resolve(),
    submission: sessionStorage.getItem('kioosk.submission'),
    reply: new URLSearchParams(location.search).get('reply'),

    async init() {
        this.syncFolder();
        window.addEventListener('hashchange', () => this.syncFolder());
        window.addEventListener('keydown', (event) => {
            if (event.key === '/' && !this.compose && !event.ctrlKey && !event.metaKey &&
                !event.target.closest?.('input, textarea, [contenteditable]')) {
                event.preventDefault();
                this.$refs.search.focus();
            }
        });
        if (!this.compose) {
            setInterval(() => {
                if (!document.hidden && !this.selected && !this.loading && this.messages.length <= 35) this.load();
            }, 30000);
            return;
        }
        if (!await this.load()) return;
        if (this.reply && !Object.values(this.draft).some(Boolean)) {
            try {
                const original = await mailRequest('message/' + encodeURIComponent(this.reply));
                this.draft = { to: original.replyTo, subject: 'پاسخ: ' + original.subject, body: '' };
            } catch (error) {
                this.notify(error.message);
            }
        }
    },
    get splitInbox() {
        return !this.compose && this.folderKey === 'imbox' && !this.query.trim();
    },
    async load(append = false, section = 'all') {
        const version = ++this.loadVersion;
        this.loading = true;
        if (!append) this.loadError = '';
        try {
            const sections = !append && this.splitInbox ? ['unread', 'seen'] : [section];
            const pages = append ? {...this.pages} : {};
            const messages = append ? [...this.messages] : [];
            for (const key of sections) {
                const position = append ? pages[key]?.position ?? 0 : 0;
                const params = new URLSearchParams({folder: this.folderKey, q: this.query.trim(), position});
                if (key !== 'all') params.set('section', key);
                const data = await mailRequest('data?' + params);
                if (version !== this.loadVersion) return false;

                messages.push(...data.messages.map(message => ({...message, time: time.format(new Date(message.receivedAt))})));
                pages[key] = {more: data.more, position: position + data.messages.length};
                if (this.compose) this.draft = {...emptyDraft(), ...data.draft};
            }
            this.messages = [...new Map(messages.map(row => [row.id, row])).values()];
            this.pages = pages;
            return true;
        } catch (error) {
            if (version === this.loadVersion) this.loadError = error.message;
            return false;
        } finally {
            if (version === this.loadVersion) this.loading = false;
        }
    },
    search() {
        this.selected = null;
        clearTimeout(this.searchTimer);
        this.searchTimer = setTimeout(() => this.load(), 250);
    },
    toggleHeaderMenu(menu) {
        this.headerMenu = this.headerMenu === menu ? null : menu;
    },
    closeHeaderMenu(restoreFocus = false) {
        if (!this.headerMenu) return;

        const triggerId = { folders: 'folder-toggle', profile: 'profile-toggle', message: 'message-toggle' }[this.headerMenu];
        this.headerMenu = null;
        if (restoreFocus === true) document.getElementById(triggerId)?.focus();
    },
    async copyAddress() {
        try {
            await navigator.clipboard.writeText(this.accountEmail);
            this.closeHeaderMenu(true);
            this.notify('نشانی ایمیل کپی شد.');
        } catch {
            this.notify('کپی خودکار ممکن نیست. نشانی را می‌توانید انتخاب کنید.');
        }
    },
    folderUrl(key) {
        const inboxUrl = this.$root.dataset.inboxUrl;
        return key === 'imbox' ? inboxUrl : inboxUrl + '#' + key;
    },
    navigateFolder(key, event) {
        if (this.compose || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return;

        event.preventDefault();
        this.closeHeaderMenu();
        this.query = '';
        if (location.hash !== (key === 'imbox' ? '' : '#' + key)) {
            history.pushState(null, '', this.folderUrl(key));
        }
        this.syncFolder();
        this.$nextTick(() => document.getElementById('main-content').focus());
    },
    syncFolder() {
        const key = location.hash.slice(1);
        this.folderKey = Object.hasOwn(folders, key) ? key : 'imbox';
        this.selected = null;
        this.closeHeaderMenu();
        if (!this.compose) this.load();
    },
    number(value) {
        return number.format(value);
    },
    get unread() {
        return this.messages.filter((message) => message.folder === 'imbox' && !message.read).length;
    },
    get visible() {
        return this.messages;
    },
    get groups() {
        if (this.splitInbox) {
            return [
                { key: 'unread', label: 'تازه برای شما', messages: this.visible.filter(message => !message.read) },
                { key: 'seen', label: 'دیده‌شده‌ها', messages: this.visible.filter(message => message.read).sort((a, b) => b.receivedAt.localeCompare(a.receivedAt)) },
            ];
        }
        return this.visible.length ? [{ key: 'all', label: 'نامه‌ها', messages: this.visible }] : [];
    },
    async openMessage(message) {
        this.selected = {...message};
        this.$nextTick(() => document.getElementById('message-heading')?.focus());
        try {
            const detail = await mailRequest('message/' + encodeURIComponent(message.id));
            if (this.selected?.id !== message.id) return;
            this.selected = {...message, ...detail, folder: message.folder, sender: message.sender, email: message.email, body: detail.text};
            if (!message.read) await this.setFlag('read', true);
        } catch (error) {
            this.notify(error.message);
        }
    },
    closeMessage() {
        const id = this.selected.id;
        this.closeHeaderMenu();
        this.selected = null;
        this.$nextTick(() => {
            const row = document.querySelector('[data-message="' + CSS.escape(id) + '"]');
            (row ?? document.getElementById('main-content')).focus();
        });
    },
    async setFlag(flag, value) {
        const id = this.selected.id;
        try {
            await mailRequest('message/' + encodeURIComponent(id), 'PATCH', {flag, value});
            const row = this.messages.find(message => message.id === id);
            if (row) row[flag] = value;
            if (this.selected?.id === id) this.selected[flag] = value;
            return true;
        } catch (error) {
            this.notify(error.message);
            return false;
        }
    },
    async toggleFlag(flag) {
        if (await this.setFlag(flag, !this.selected[flag])) {
            this.closeHeaderMenu(true);
            this.notify('وضعیت نامه تغییر کرد.');
        }
    },
    notify(message) {
        this.notice = message;
        clearTimeout(this.noticeTimer);
        this.noticeTimer = setTimeout(() => { this.notice = ''; }, 3200);
    },
    saveDraft() {
        clearTimeout(this.draftTimer);
        this.draftTimer = setTimeout(() => this.writeDraft(), 400);
    },
    writeDraft() {
        const draft = {...this.draft};
        this.draftWrite = this.draftWrite.then(() => mailRequest('draft', 'PUT', draft))
            .catch(error => this.notify(error.message));
        return this.draftWrite;
    },
    discardDraft() {
        this.draft = emptyDraft();
        this.saveDraft();
    },
    async send() {
        if (this.busy) return;
        this.busy = true;
        try {
            clearTimeout(this.draftTimer);
            await this.writeDraft();
            this.submission ??= crypto.randomUUID();
            sessionStorage.setItem('kioosk.submission', this.submission);
            await mailRequest('send', 'POST', {...this.draft, submission: this.submission, reply: this.reply});
            sessionStorage.removeItem('kioosk.submission');
            location.assign(this.folderUrl('sent'));
        } catch (error) {
            this.notify(error.message);
        } finally {
            this.busy = false;
        }
    },
}));

Alpine.start();
