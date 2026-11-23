import Alpine from 'alpinejs';

const icon = (paths) => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' + paths + '</svg>';
const thumb = '<path d="M7 10v11m0-11 5-7a2 2 0 0 1 2 2l-.8 5H19a2 2 0 0 1 2 2.4l-1.4 7A2 2 0 0 1 17.7 21H7m0-11H4a1 1 0 0 0-1 1v9a1 1 0 0 0 1 1h3"/>';
const icons = {
    thumbsUp: icon(thumb),
    thumbsDown: icon('<g transform="rotate(180 12 12)">' + thumb + '</g>'),
    inbox: icon('<path d="M4 4h16l2 10v4a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2v-4L4 4Z"/><path d="M2 14h6l2 3h4l2-3h6"/>'),
    feed: icon('<rect x="4" y="4" width="16" height="16" rx="3"/><path d="M8 9h8m-8 4h8m-8 4h4"/>'),
    receipt: icon('<path d="M6 3l3 2 3-2 3 2 3-2v18l-3-2-3 2-3-2-3 2V3Z"/><path d="M9 10h6m-6 4h5"/>'),
    clock: icon('<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>'),
    bookmark: icon('<path d="M6 5a2 2 0 0 1 2-2h8a2 2 0 0 1 2 2v16l-6-4-6 4V5Z"/>'),
    send: icon('<path d="m22 2-7 20-4-9-9-4 20-7ZM22 2 11 13"/>'),
    edit: icon('<path d="m14 5 5 5M4 20l5-1L20 8a3.5 3.5 0 0 0-5-5L4 14l-1 6Z"/>'),
    screener: icon('<path d="M4 6h16l-6 7v5l-4 2v-7L4 6Z"/>'),
    search: icon('<circle cx="10.5" cy="10.5" r="6.5"/><path d="m16 16 4 4"/>'),
    more: icon('<circle cx="5" cy="12" r="1"/><circle cx="12" cy="12" r="1"/><circle cx="19" cy="12" r="1"/>'),
    reply: icon('<path d="m14 5 6 6-6 6v-4h-3c-4 0-6 2-7 5v-2a7 7 0 0 1 7-7h3V5Z"/>'),
    forward: icon('<path d="m14 5 6 6-6 6v-4h-3c-4 0-6 2-7 5v-2a7 7 0 0 1 7-7h3V5Z"/>'),
    label: icon('<path d="M3 3h8l10 10-8 8L3 11V3Z M7 7h.01"/>'),
    move: icon('<path d="M3 6h6l2 2h10v12H3z M8 14h8m-3-3 3 3-3 3"/>'),
    trash: icon('<path d="M3 6h18M9 6V3h6v3M5 6l1 14h12l1-14M10 10v6m4-6v6"/>'),
    chevron: icon('<path d="m6 9 6 6 6-6"/>'),
    back: icon('<path d="m13 6 6 6-6 6m6-6H5"/>'),
};

const folders = {
    imbox: { title: 'نامه‌های مهم', icon: 'inbox', description: 'نامه‌هایی که می‌خواهید زودتر ببینید.' },
    feed: { title: 'خواندنی‌ها', icon: 'feed', description: 'خبرنامه‌ها و مطالبی برای وقت آزاد.' },
    receipts: { title: 'رسیدها', icon: 'receipt', description: 'رسیدها و تأییدیه‌ها برای مراجعهٔ بعدی.' },
    later: { title: 'پاسخ بعداً', icon: 'clock', description: 'نامه‌هایی که می‌خواهید بعداً پاسخ دهید.' },
    saved: { title: 'کنارگذاشته‌ها', icon: 'bookmark', description: 'نامه‌هایی که می‌خواهید دم دست باشند.' },
    trash: { title: 'زباله‌دان', icon: 'trash', description: 'نامه‌هایی که به زباله‌دان منتقل کرده‌اید.' },
    screener: { title: 'بررسی فرستنده‌ها', icon: 'screener' },
    'screener-history': { title: 'سابقهٔ بررسی', icon: 'clock' },
    sent: { title: 'ارسال‌شده‌ها', icon: 'send', description: 'نامه‌هایی که در نسخهٔ آزمایشی فرستاده‌اید.' },
};

const emptyDraft = () => ({to: [], cc: [], bcc: [], subject: '', bodyHtml: '', attachments: [], files: [], reply: null, forward: null, quote: null});
const number = new Intl.NumberFormat('fa-IR');
const time = new Intl.DateTimeFormat('fa-IR', { month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' });
const bulkFlags = {
    read: {flag: 'read', value: true},
    unread: {flag: 'read', value: false},
    later: {flag: 'later', value: true},
    unlater: {flag: 'later', value: false},
    save: {flag: 'saved', value: true},
    unsave: {flag: 'saved', value: false},
};

const mailLink = /^(?:https?:\/\/|mailto:)[^\s\x00-\x1f]+$/i;
const clipboardTags = new Set([
    'a', 'b', 'blockquote', 'br', 'code', 'div', 'em', 'i', 'li', 'ol', 'p', 'pre', 's', 'strong', 'u', 'ul',
]);
const clipboardIgnored = new Set(['head', 'script', 'style', 'svg', 'math', 'iframe', 'object', 'embed', 'template']);

function clipboardMarkup(html) {
    const template = document.createElement('template');
    template.innerHTML = html;
    const clean = document.createElement('div');
    const copy = (source, target) => {
        for (const child of source.childNodes) {
            if (child.nodeType === Node.TEXT_NODE) {
                target.append(document.createTextNode(child.textContent));
            } else if (child.nodeType === Node.ELEMENT_NODE && !clipboardIgnored.has(child.localName) && !child.hidden) {
                if (!clipboardTags.has(child.localName)) {
                    copy(child, target);
                    continue;
                }
                const element = document.createElement(child.localName);
                const dir = child.getAttribute('dir')?.toLowerCase();
                const href = child.getAttribute('href')?.trim();
                if (['auto', 'ltr', 'rtl'].includes(dir)) element.dir = dir;
                if (child.localName === 'a' && href && mailLink.test(href)) element.setAttribute('href', href);
                copy(child, element);
                target.append(element);
            }
        }
    };
    copy(template.content, clean);
    return clean.innerHTML;
}

const spinnerVisible = () => new Promise(resolve => setTimeout(resolve, 450));

async function mailRequest(path, method = 'GET', payload, keepalive = false) {
    const body = payload === undefined ? undefined : JSON.stringify(payload);
    const response = await fetch('/mail/' + path, {
        method,
        headers: {
            'Accept': 'application/json',
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
        },
        body,
        keepalive: keepalive && new Blob([body]).size < 60000,
    });
    if (response.status === 204) return null;
    const data = await response.json();
    if (!response.ok) {
        const error = new Error(response.status === 401 || response.status === 419
            ? 'برای ادامه دوباره وارد حساب شوید.'
            : Object.values(data.errors ?? {})[0]?.[0] ?? data.message ?? 'ارتباط با صندوق برقرار نشد.');
        error.status = response.status;
        throw error;
    }
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
    recipientInputs: {to: '', cc: '', bcc: ''},
    recipientErrors: {},
    ccOpen: false,
    bccOpen: false,
    uploading: null,
    uploadRequest: null,
    draftStatus: '',
    draftId: null,
    draftRevision: 0,
    draftDirty: false,
    draftSaving: null,
    hasLegacyDraft: false,
    editorSelection: null,
    editorLink: '',
    editorLinkError: '',
    editorEmpty: true,
    get compose() {
        return this.$root.dataset.composing === 'true';
    },
    get composeUrl() {
        return this.$root.dataset.composeUrl;
    },
    folderKey: 'imbox',
    query: '',
    selected: null,
    checkedIds: [],
    mailboxView: 0,
    bulkAction: '',
    bulkLabel: '',
    bulkDestination: 'imbox',
    bulkBusy: false,
    bulkFeedback: '',
    screeningCount: 0,
    screeningSenders: [],
    screeningHistory: [],
    screeningFilter: 'all',
    screeningLimit: 25,
    screeningPreview: null,
    screeningBusy: false,
    screeningError: '',
    screeningFeedback: '',
    screeningUndo: null,
    get screeningEnabled() {
        return this.$root.dataset.screening === 'true';
    },
    get screeningView() {
        return this.folderKey === 'screener' || this.folderKey === 'screener-history';
    },
    get filteredScreeningHistory() {
        return this.screeningHistory.filter(row => this.screeningFilter === 'all'
            || (row.destination === 'out') === (this.screeningFilter === 'out'));
    },
    get navigationFolders() {
        return Object.fromEntries(Object.entries(this.folders).filter(([key]) => key !== 'screener-history'
            && (key !== 'screener' || this.screeningEnabled)));
    },
    threadAction: '',
    threadError: '',
    threadBusy: false,
    labelInput: '',
    destination: 'imbox',
    forward: new URLSearchParams(location.search).get('forward'),
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
    reply: new URLSearchParams(location.search).get('reply'),
    replyAll: new URLSearchParams(location.search).get('all') === '1',

    async init() {
        this.syncFolder();
        window.addEventListener('hashchange', () => this.syncFolder());
        window.addEventListener('keydown', (event) => {
            if (event.key === '/' && !this.compose && !this.screeningView && !event.ctrlKey && !event.metaKey &&
                !event.target.closest?.('input, textarea, [contenteditable]')) {
                event.preventDefault();
                this.$refs.search.focus();
            }
        });
        if (!this.compose) {
            setInterval(() => {
                if (document.hidden || this.selected || this.loading || this.screeningBusy) return;
                if (this.screeningView) {
                    if (!this.screeningPreview) this.load();
                } else {
                    if (!this.checkedIds.length && !this.bulkBusy && !this.loadError && this.messages.length <= 35) this.load();
                    this.loadScreeningCount();
                }
            }, 30000);
            return;
        }
        document.addEventListener('visibilitychange', () => {
            if (document.hidden && !this.busy && this.draftDirty) this.writeDraft(true);
        });
        await this.openComposer();
    },
    async openComposer() {
        const params = new URLSearchParams(location.search);
        this.loading = true;
        this.loadError = '';
        try {
            const {id, revision, draft, hasLegacyDraft} = await mailRequest('draft/open', 'POST', {
                reply: this.reply, forward: this.forward, all: this.replyAll,
                ...(params.get('recover') === '1' ? {recover: true} : {}),
            });
            this.draftId = id;
            this.draftRevision = revision;
            this.draft = {...emptyDraft(), ...draft};
            this.draft.subject ??= '';
            this.ccOpen = this.draft.cc.length > 0;
            this.bccOpen = this.draft.bcc.length > 0;
            this.hasLegacyDraft = hasLegacyDraft;
            this.loading = false;
            await this.$nextTick();
            const editor = this.$refs.editor;
            editor.innerHTML = this.draft.bodyHtml;
            this.editorEmpty = !editor.textContent.trim();
            document.execCommand('defaultParagraphSeparator', false, 'p');
            document.execCommand('styleWithCSS', false, false);
            if (this.reply && document.activeElement === document.body) {
                editor.focus();
                const selection = window.getSelection();
                selection.selectAllChildren(editor);
                selection.collapseToEnd();
            }
        } catch (error) {
            this.loadError = error.message;
        } finally {
            this.loading = false;
        }
    },
    get splitInbox() {
        return !this.compose && this.folderKey === 'imbox' && !this.query.trim();
    },
    reloadMailbox() {
        return this.load(false, 'all', this.pages);
    },
    async load(append = false, section = 'all', previousPages = {}) {
        if (this.screeningView) return this.loadScreening();
        const version = ++this.loadVersion;
        const minimumDisplay = !append && !Object.keys(this.pages).length ? spinnerVisible() : null;
        this.loading = true;
        if (!append) this.loadError = '';
        try {
            const sections = !append && this.splitInbox ? ['unread', 'seen'] : [section];
            const pages = append ? {...this.pages} : {};
            const messages = append ? [...this.messages] : [];
            for (const key of sections) {
                let position = append ? pages[key]?.position ?? 0 : 0;
                const previousPosition = previousPages[key]?.position ?? 0;
                do {
                    const params = new URLSearchParams({folder: this.folderKey, q: this.query.trim(), position});
                    if (key !== 'all') params.set('section', key);
                    const data = await mailRequest('data?' + params);
                    if (version !== this.loadVersion) return false;

                    messages.push(...data.messages.map(message => ({...message, time: time.format(new Date(message.receivedAt))})));
                    position += data.messages.length;
                    pages[key] = {more: data.more && position <= 100000 && data.messages.length > 0, position};
                } while (pages[key].more && position < previousPosition);
            }
            if (minimumDisplay) await minimumDisplay;
            if (version !== this.loadVersion) return false;
            this.messages = [...new Map(messages.map(row => [row.id, row])).values()];
            this.pages = pages;
            if (this.checkedIds.length) {
                const visibleIds = new Set(this.messages.map(message => message.id));
                this.checkedIds = this.checkedIds.filter(id => visibleIds.has(id));
                if (!this.checkedIds.length) this.clearBulkSelection();
            }
            return true;
        } catch (error) {
            if (minimumDisplay) await minimumDisplay;
            if (version === this.loadVersion) this.loadError = error.message;
            return false;
        } finally {
            if (version === this.loadVersion) this.loading = false;
        }
    },
    search() {
        this.selected = null;
        this.resetMailboxView();
        this.loading = true;
        this.searchTimer = setTimeout(() => this.load(), 250);
    },
    resetMailboxView() {
        this.mailboxView++;
        this.loadVersion++;
        clearTimeout(this.searchTimer);
        this.clearBulkSelection();
        this.messages = [];
        this.pages = {};
        this.loadError = '';
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
        this.resetMailboxView();
        const key = location.hash.slice(1);
        this.folderKey = Object.hasOwn(folders, key) ? key : 'imbox';
        if (this.screeningView && !this.screeningEnabled) this.folderKey = 'imbox';
        this.selected = null;
        this.query = '';
        this.screeningPreview = null;
        this.screeningLimit = 25;
        this.screeningError = '';
        this.closeHeaderMenu();
        if (!this.compose) {
            this.load();
            if (!this.screeningView) this.loadScreeningCount();
        }
    },
    async loadScreeningCount() {
        if (!this.screeningEnabled) return;
        const version = this.loadVersion;
        try {
            const {count} = await mailRequest('screener?count=1');
            if (version === this.loadVersion) this.screeningCount = count;
        } catch {
            // Keep the last count until the next refresh. The queue has its own retry state.
        }
    },
    async loadScreening() {
        const version = ++this.loadVersion;
        const history = this.folderKey === 'screener-history';
        this.loading = true;
        this.loadError = '';
        try {
            const data = await mailRequest(history ? 'screener?history=1' : 'screener');
            if (version !== this.loadVersion) return;
            if (history) {
                this.screeningHistory = data.history;
            } else {
                this.screeningCount = data.count;
                this.screeningSenders = data.senders;
                if (this.screeningPreview && !data.senders.some(sender => sender.address === this.screeningPreview.address)) {
                    this.screeningPreview = null;
                }
            }
        } catch (error) {
            if (version === this.loadVersion) this.loadError = error.message;
        } finally {
            if (version === this.loadVersion) this.loading = false;
        }
    },
    screeningTime(value) {
        return time.format(new Date(value));
    },
    async previewSender(sender, message = sender.messages[0]) {
        if (this.screeningPreview?.id === message.id && !this.screeningPreview.error) {
            this.screeningPreview = null;
            return;
        }
        this.screeningPreview = {address: sender.address, id: message.id, loading: true};
        try {
            const detail = await mailRequest('screener/preview/' + encodeURIComponent(message.id));
            if (this.screeningPreview?.id === message.id) {
                this.screeningPreview = {...detail, address: sender.address, id: message.id};
            }
        } catch (error) {
            if (this.screeningPreview?.id === message.id) {
                this.screeningPreview = {address: sender.address, id: message.id, error: error.message};
            }
        }
    },
    async submitScreening(sender, destination, index = 0) {
        if (this.screeningBusy) return;
        this.screeningBusy = true;
        this.screeningError = '';
        const currentFolder = this.folderKey;
        const root = this.$root;
        try {
            const result = await mailRequest('screener/decision', 'POST', {
                address: sender.address, destination, revision: sender.revision ?? null,
            });
            this.screeningFeedback = result.syncing ? 'تصمیم ثبت شد. جابه‌جایی نامه‌ها در انتظار انجام است.'
                : destination === null ? 'تصمیم بازگردانده شد.'
                : destination === 'out' ? 'فرستنده رد شد.'
                : 'فرستنده پذیرفته شد. مقصد: ' + this.folders[destination].title;
            this.screeningUndo = destination === null ? null : result;
            if (this.folderKey === currentFolder) {
                if (this.screeningPreview?.address === sender.address) this.screeningPreview = null;
                await this.loadScreening();
            }
            this.screeningBusy = false;
            if (this.folderKey === currentFolder) this.$nextTick(() => {
                const selector = destination === 'out' && currentFolder === 'screener'
                    ? '[data-screening-reject]' : '[data-screening-focus]';
                const actions = [...root.querySelectorAll(selector)].filter(action => action.getClientRects().length);
                (actions[Math.min(index, actions.length - 1)] ?? root.querySelector('#screening-heading'))?.focus();
            });
        } catch (error) {
            if (error.status === 409 && this.folderKey === currentFolder) {
                this.screeningUndo = null;
                await this.loadScreening();
            }
            this.screeningError = error.message;
        } finally {
            this.screeningBusy = false;
        }
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
    get bulkLabels() {
        const ids = new Set(this.checkedIds);
        return [...new Set(this.messages.filter(message => ids.has(message.id)).flatMap(message => message.labels ?? []))]
            .sort((first, second) => first.localeCompare(second, 'fa'));
    },
    get bulkActionReady() {
        switch (this.bulkAction) {
            case 'label':
                return Boolean(this.bulkLabel.trim());
            case 'unlabel':
                return this.bulkLabels.includes(this.bulkLabel);
            default:
                return Boolean(this.bulkAction);
        }
    },
    toggleBulkSelection(id, checked) {
        if (!this.checkedIds.length && checked) this.bulkDestination = this.folderKey === 'imbox' ? 'feed' : 'imbox';
        this.checkedIds = checked ? [...this.checkedIds, id] : this.checkedIds.filter(checkedId => checkedId !== id);
        this.bulkFeedback = '';
        if (!this.checkedIds.length) this.clearBulkSelection();
    },
    clearBulkSelection(restoreFocus = false) {
        const id = this.checkedIds[0];
        this.checkedIds = [];
        this.bulkAction = '';
        this.bulkLabel = '';
        this.bulkFeedback = '';
        if (restoreFocus) this.focusMailboxRow(id);
    },
    focusMailboxRow(id) {
        this.$nextTick(() => {
            const row = id === undefined ? null : document.querySelector('[data-message="' + CSS.escape(id) + '"]');
            (row ?? document.getElementById('main-content')).focus();
        });
    },
    submitBulkAction() {
        if (!this.bulkActionReady || this.bulkBusy || this.loading || !this.checkedIds.length) return;
        if (this.bulkAction === 'trash') {
            this.$refs.bulkTrashDialog.showModal();
        } else {
            this.applyBulkAction();
        }
    },
    async applyBulkAction() {
        if (!this.bulkActionReady || this.bulkBusy || this.loading || !this.checkedIds.length) return;

        const ids = [...this.checkedIds];
        const view = this.mailboxView;
        const action = this.bulkAction;
        const flag = bulkFlags[action];
        const payload = flag ?? (action === 'move' || action === 'trash'
            ? {action: 'move', destination: action === 'trash' ? 'trash' : this.bulkDestination}
            : {action, label: action === 'label' ? this.bulkLabel.trim() : this.bulkLabel});
        const failed = [];
        let failureMessage = '';
        this.bulkBusy = true;
        this.bulkFeedback = '';

        try {
            for (let offset = 0; offset < ids.length; offset += 4) {
                const batch = ids.slice(offset, offset + 4);
                const results = await Promise.allSettled(batch.map(id => mailRequest(
                    'message/' + encodeURIComponent(id) + (flag ? '' : '/organize'),
                    flag ? 'PATCH' : 'POST',
                    payload,
                )));
                results.forEach((result, index) => {
                    if (result.status === 'rejected') {
                        failed.push(batch[index]);
                        failureMessage ||= result.reason.status ? result.reason.message : 'ارتباط با صندوق برقرار نشد.';
                    }
                });
            }
            if (view !== this.mailboxView) return;

            const succeeded = ids.length - failed.length;
            const feedback = this.number(succeeded) + ' نامه تغییر کرد، ' + this.number(failed.length) + ' ناموفق.'
                + (failureMessage ? ' ' + failureMessage : '');
            this.checkedIds = failed;
            this.bulkFeedback = failed.length ? feedback : '';
            this.notify(feedback);
            if (succeeded) await this.reloadMailbox();
            if (view !== this.mailboxView) return;

            if (!this.checkedIds.length) {
                this.clearBulkSelection();
                if (document.activeElement === document.body || this.$refs.bulkBar?.contains(document.activeElement)) {
                    this.focusMailboxRow(ids[0]);
                }
            }
        } finally {
            this.bulkBusy = false;
            if (view === this.mailboxView && this.checkedIds.length && document.activeElement === document.body) {
                this.$nextTick(() => this.$refs.bulkActionMenu?.focus());
            }
        }
    },
    async openMessage(message) {
        this.clearBulkSelection();
        this.selected = {...message, loading: true};
        const minimumDisplay = spinnerVisible();
        this.$nextTick(() => document.getElementById('message-heading')?.focus());
        try {
            const [detail] = await Promise.all([mailRequest('message/' + encodeURIComponent(message.id)), minimumDisplay]);
            if (this.selected?.id !== message.id) return;
            this.selected = {...message, ...detail, folder: message.folder, sender: message.sender, email: message.email, body: detail.text};
            if (!message.read) await this.setFlag('read', true);
        } catch (error) {
            await minimumDisplay;
            if (this.selected?.id === message.id) this.selected.loading = false;
            this.notify(error.message);
        }
    },
    closeMessage() {
        const id = this.selected.id;
        this.closeHeaderMenu();
        this.selected = null;
        this.focusMailboxRow(id);
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
    openThreadAction(action) {
        this.closeHeaderMenu(true);
        this.threadAction = action;
        this.threadError = '';
        this.destination = this.folderKey === 'imbox' ? 'feed' : 'imbox';
        this.labelInput = '';
        this.$refs.threadDialog.showModal();
    },
    async applyThreadAction(removeLabel = null) {
        if (this.threadBusy) return;
        const id = this.selected.id;
        const action = this.threadAction;
        this.threadBusy = true;
        this.threadError = '';
        try {
            if (action === 'unread') {
                await mailRequest('message/' + encodeURIComponent(id), 'PATCH', {flag: 'read', value: false});
            } else {
                await mailRequest('message/' + encodeURIComponent(id) + '/organize', 'POST',
                    action === 'label'
                        ? {action: removeLabel === null ? 'label' : 'unlabel', label: removeLabel ?? this.labelInput.trim()}
                        : {action: 'move', destination: action === 'trash' ? 'trash' : this.destination});
            }
            if (action === 'label') {
                this.selected.labels = removeLabel === null
                    ? [...new Set([...this.selected.labels, this.labelInput.trim()])]
                    : this.selected.labels.filter(label => label !== removeLabel);
            }
            this.$refs.threadDialog.close();
            if (action !== 'label') {
                this.closeMessage();
                await this.load();
            }
            this.notify('تغییر نامه ذخیره شد.');
        } catch (error) {
            this.threadError = error.message;
        } finally {
            this.threadBusy = false;
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
    get hasDraft() {
        return Boolean(this.draft.to.length || this.draft.cc.length || this.draft.bcc.length ||
            this.draft.subject || !this.editorEmpty || this.draft.attachments.length);
    },
    commitRecipients(field) {
        const value = this.recipientInputs[field].trim();
        if (!value) return true;

        const entries = value.split(/[,;،؛\s]+/u).filter(Boolean);
        const invalid = entries.find(address => !/^[^\s@]+@[^\s@]+\.[^\s@]+$/u.test(address) || address.length > 254);
        if (invalid) {
            this.recipientErrors[field] = 'نشانی معتبر وارد کنید.';
            return false;
        }

        const unique = new Map(this.draft[field].map(address => [address.toLowerCase(), address]));
        entries.forEach(address => unique.set(address.toLowerCase(), address));
        this.draft[field] = [...unique.values()];
        this.recipientInputs[field] = '';
        delete this.recipientErrors[field];
        this.saveDraft();
        return true;
    },
    removeRecipient(field, address) {
        this.draft[field] = this.draft[field].filter(item => item !== address);
        this.saveDraft();
    },
    pasteRecipients(field, event) {
        const text = event.clipboardData?.getData('text') ?? '';
        if (!/[,;،؛\s]/u.test(text.trim())) return;
        event.preventDefault();
        this.recipientInputs[field] += ' ' + text;
        this.commitRecipients(field);
    },
    editorChanged() {
        const editor = this.$refs.editor;
        this.editorEmpty = !editor.textContent.trim();
        const html = this.editorEmpty ? '' : editor.innerHTML;
        if (this.draft.bodyHtml !== html) {
            this.draft.bodyHtml = html;
            this.saveDraft();
        }
    },
    rememberSelection() {
        const selection = window.getSelection();
        if (selection.rangeCount && this.$refs.editor.contains(selection.anchorNode) &&
            this.$refs.editor.contains(selection.focusNode)) {
            this.editorSelection = selection.getRangeAt(0).cloneRange();
        }
    },
    restoreSelection() {
        const editor = this.$refs.editor;
        editor.focus();
        if (this.editorSelection && editor.contains(this.editorSelection.commonAncestorContainer)) {
            const selection = window.getSelection();
            selection.removeAllRanges();
            selection.addRange(this.editorSelection);
        }
    },
    format(command, value = null) {
        this.restoreSelection();
        document.execCommand(command, false, value);
        this.rememberSelection();
        this.editorChanged();
    },
    pasteEditor(event) {
        const rawHtml = event.clipboardData?.getData('text/html') ?? '';
        const html = rawHtml && clipboardMarkup(rawHtml);
        if (html) {
            document.execCommand('insertHTML', false, html);
        } else {
            document.execCommand('insertText', false, event.clipboardData?.getData('text/plain') ?? '');
        }
        this.editorChanged();
    },
    openLinkEditor() {
        this.rememberSelection();
        this.editorLink = '';
        this.editorLinkError = '';
        this.$refs.linkDialog.showModal();
    },
    insertLink() {
        const href = this.editorLink.trim();
        if (!mailLink.test(href)) {
            this.editorLinkError = 'نشانی پیوند را با https:// یا mailto: وارد کنید.';
            return;
        }
        this.$refs.linkDialog.close();
        this.restoreSelection();
        if (window.getSelection().isCollapsed) {
            const link = document.createElement('a');
            link.setAttribute('href', href);
            link.textContent = href;
            this.format('insertHTML', link.outerHTML);
        } else {
            this.format('createLink', href);
        }
        if (window.getSelection().rangeCount) window.getSelection().collapseToEnd();
        this.rememberSelection();
    },
    async addFiles(files) {
        if (this.uploading || this.busy) return;
        for (const file of files) {
            if (this.draft.files.length >= 10 || file.size > 8 * 1024 * 1024 ||
                this.draft.files.reduce((size, item) => size + item.size, 0) + file.size > 16 * 1024 * 1024) {
                this.notify('هر پیوست حداکثر ۸ مگابایت، مجموع پیوست‌ها حداکثر ۱۶ مگابایت.');
                return;
            }
            this.uploading = {name: file.name, progress: 0};
            try {
                const result = await this.uploadFile(file);
                this.draft.files.push(result);
                this.draft.attachments.push(result.id);
                this.saveDraft();
                if (!await this.writeDraft()) break;
            } catch (error) {
                this.notify(error.message);
                break;
            } finally {
                this.uploading = null;
                this.uploadRequest = null;
            }
        }
    },
    uploadFile(file) {
        return new Promise((resolve, reject) => {
            const xhr = new XMLHttpRequest();
            this.uploadRequest = xhr;
            const form = new FormData();
            form.append('file', file);
            xhr.open('POST', '/mail/draft/' + this.draftId + '/attachments');
            xhr.setRequestHeader('Accept', 'application/json');
            xhr.setRequestHeader('X-CSRF-TOKEN', document.querySelector('meta[name=csrf-token]').content);
            xhr.upload.onprogress = event => {
                if (event.lengthComputable) this.uploading.progress = Math.round(event.loaded / event.total * 100);
            };
            xhr.responseType = 'json';
            xhr.onload = () => xhr.status === 201 ? resolve(xhr.response) :
                reject(new Error(xhr.response?.errors?.file?.[0] ?? xhr.response?.message ?? 'بارگذاری پیوست انجام نشد.'));
            xhr.onerror = () => reject(new Error('ارتباط هنگام بارگذاری قطع شد.'));
            xhr.onabort = () => reject(new Error('بارگذاری لغو شد.'));
            xhr.send(form);
        });
    },
    async removeFile(id) {
        this.draft.attachments = this.draft.attachments.filter(item => item !== id);
        this.draft.files = this.draft.files.filter(file => file.id !== id);
        this.saveDraft();
        await this.writeDraft();
    },
    saveDraft() {
        this.draftDirty = true;
        this.draftStatus = 'در انتظار ذخیره…';
        clearTimeout(this.draftTimer);
        this.draftTimer = setTimeout(() => this.writeDraft(), 400);
    },
    async writeDraft(keepalive = false) {
        clearTimeout(this.draftTimer);
        if (this.draftSaving) {
            if (!await this.draftSaving) return false;
            return this.writeDraft(keepalive);
        }
        if (!this.draftDirty || !this.draftId) return true;

        const {to, cc, bcc, subject, bodyHtml, attachments} = this.draft;
        this.draftDirty = false;
        this.draftStatus = 'در حال ذخیره…';
        this.draftSaving = mailRequest('draft/' + this.draftId, 'PUT', {
            to, cc, bcc, subject, bodyHtml, attachments, revision: this.draftRevision,
        }, keepalive).then(({revision}) => {
            this.draftRevision = revision;
            this.draftStatus = this.draftDirty ? 'در انتظار ذخیره…' : 'پیش‌نویس ذخیره شد';
            return true;
        }).catch(error => {
            this.draftDirty = true;
            this.draftStatus = 'پیش‌نویس ذخیره نشد';
            this.notify(error.message);
            return false;
        }).finally(() => {
            this.draftSaving = null;
        });
        return this.draftSaving;
    },
    async leaveComposer(event) {
        if (!this.compose || event.defaultPrevented || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return;
        const link = event.target.closest('a[href]');
        if (!link || link.target || !link.href.startsWith(location.origin + '/') || link.hash === '#main-content') return;
        if (this.busy || this.uploading) {
            event.preventDefault();
            return;
        }
        event.preventDefault();
        for (const field of ['to', 'cc', 'bcc']) this.commitRecipients(field);
        if (await this.writeDraft()) location.assign(link.href);
    },
    async discardDraft() {
        if (this.busy || this.uploading) return;
        this.busy = true;
        clearTimeout(this.draftTimer);
        try {
            await this.draftSaving;
            await mailRequest('draft/' + this.draftId, 'DELETE', {revision: this.draftRevision});
            this.draftDirty = false;
            location.assign(this.folderUrl('imbox'));
        } catch (error) {
            this.notify(error.message);
        } finally {
            this.busy = false;
        }
    },
    async send() {
        if (this.busy || this.uploading) return;
        if (!['to', 'cc', 'bcc'].every(field => this.commitRecipients(field))) return;
        if (!this.draft.to.length) {
            this.recipientErrors.to = 'دست‌کم یک گیرنده وارد کنید.';
            this.$refs.recipientTo.focus();
            return;
        }
        this.busy = true;
        try {
            if (!await this.writeDraft()) return;
            await mailRequest('send', 'POST', {draft: this.draftId, revision: this.draftRevision});
            location.assign(this.folderUrl('sent'));
        } catch (error) {
            this.notify(error.message);
        } finally {
            this.busy = false;
        }
    },

}));

Alpine.start();
