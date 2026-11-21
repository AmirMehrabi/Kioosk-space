@php
    $account = auth()->user();
    $mailboxAddress = $account->mailbox_address ?? $account->email;
    $initial = mb_substr($account->name, 0, 1);
@endphp
<!doctype html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <meta name="theme-color" content="#fdfcfb">
    <title>کیوسک | نامه‌ها</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body x-data="mailbox"
    data-account-id="{{ $account->getKey() }}"
    data-account-name="{{ $account->name }}"
    data-account-email="{{ $mailboxAddress }}"
    data-account-initial="{{ $initial }}"
    data-inbox-url="{{ route('imbox', absolute: false) }}"
    data-compose-url="{{ route('compose', absolute: false) }}"
    data-composing="{{ ($composing ?? false) ? 'true' : 'false' }}" :class="{'reading-page': selected}" x-cloak>
    <a href="#main-content" class="skip-link" @click.prevent="document.getElementById('main-content').focus()">رفتن به محتوای اصلی</a>
    <header class="masthead" @keydown.escape="closeHeaderMenu(true)" @click.outside="if (headerMenu !== 'message') closeHeaderMenu()">
        <div class="masthead-inner">
            <div class="header-context">
                <nav class="breadcrumbs" aria-label="مسیر صفحه">
                    <button type="button" class="breadcrumb-pill" x-show="selected" @click="closeMessage">
                        <span x-html="icons.back"></span><span class="breadcrumb-label">بازگشت</span>
                    </button>
                    <a href="{{ route('imbox', absolute: false) }}" class="breadcrumb-pill" x-show="compose">
                        <span x-html="icons.back"></span><span class="breadcrumb-label">بازگشت</span>
                    </a>
                    <span class="breadcrumb-pill breadcrumb-current" x-show="!selected && !compose" aria-current="page" x-text="folders[folderKey].title"></span>
                </nav>
                <label class="search" x-show="!compose" @focusin="closeHeaderMenu">
                    <span x-html="icons.search"></span>
                    <input type="search" placeholder="جست‌وجو" aria-label="جست‌وجو در نامه‌ها" x-model="query" x-ref="search" @input="search">
                </label>
            </div>

            <div class="header-brand" @focusout="if (!$el.contains($event.relatedTarget) && headerMenu === 'folders') closeHeaderMenu()">
                <button type="button" class="brand folder-toggle" id="folder-toggle" aria-label="کیوسک، پوشه‌های نامه"
                    aria-controls="folder-dropdown" :aria-expanded="headerMenu === 'folders'" @click="toggleHeaderMenu('folders')">
                    <span class="brand-mark" x-html="icons.brand"></span>
                    <span>کیوسک</span>
                    <span class="brand-chevron" x-html="icons.chevron"></span>
                </button>
                <nav class="folder-dropdown" id="folder-dropdown" aria-label="پوشه‌های نامه" x-show="headerMenu === 'folders'">
                    <template x-for="(folder, key) in folders" :key="key">
                        <a :href="folderUrl(key)" class="nav-item" :class="{active: !compose && folderKey === key}"
                            @click="navigateFolder(key, $event)" :aria-current="!compose && folderKey === key ? 'page' : null">
                            <span x-html="icons[folder.icon]"></span><span x-text="folder.title"></span>
                            <small x-show="key === 'imbox' && unread" x-text="number(unread)"></small>
                        </a>
                    </template>
                    <a class="nav-item dropdown-compose" href="{{ route('compose', absolute: false) }}">
                        <span x-html="icons.edit"></span> نامهٔ جدید
                    </a>
                </nav>
            </div>

            <div class="header-actions">
                <a class="secondary compose-link" href="{{ route('compose', absolute: false) }}" aria-label="نامهٔ جدید">
                    <span x-html="icons.edit"></span><span class="compose-label">نامهٔ جدید</span>
                </a>
                <div class="profile" @focusout="if (!$el.contains($event.relatedTarget) && headerMenu === 'profile') closeHeaderMenu()">
                    <button type="button" class="profile-toggle" id="profile-toggle" aria-label="نمایهٔ {{ $account->name }}"
                        aria-controls="profile-dropdown" :aria-expanded="headerMenu === 'profile'" @click="toggleHeaderMenu('profile')">
                        <span class="avatar" aria-hidden="true">{{ $initial }}</span>
                    </button>
                    <div class="profile-dropdown" id="profile-dropdown" x-show="headerMenu === 'profile'">
                        <strong>{{ $account->name }}</strong>
                        <span dir="ltr">{{ $mailboxAddress }}</span>
                        <button type="button" class="secondary" @click="copyAddress">کپی نشانی ایمیل</button>
                        <form method="post" action="{{ route('logout') }}">
                            @csrf
                            <button type="submit" class="secondary">خروج از حساب</button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </header>

    <div class="workspace">
        <main class="content" id="main-content" tabindex="-1">
            <div class="empty" x-show="loadError" role="alert"><p x-text="loadError"></p><button class="secondary" @click="load()">تلاش دوباره</button></div>
            <template x-if="!compose && !selected">
                <div>
                    <section class="intro" :class="{'hero': folderKey === 'imbox' && !query}">
                        <div>
                            <p class="eyebrow" x-show="folderKey === 'imbox' && !query"><span></span> صندوق {{ $account->name }}</p>
                            <h1 x-text="query ? 'نتایج جست‌وجو' : folders[folderKey].title"></h1>
                            <p class="intro-description" x-text="query ? number(visible.length) + ' نامه پیدا شد' : folders[folderKey].description"></p>
                        </div>
                    </section>
                    <template x-for="group in groups" :key="group.key">
                        <section class="message-group" :aria-label="group.label">
                            <div class="group-title"><h2 x-text="group.label"></h2><span x-show="group.messages.length" x-text="number(group.messages.length)"></span></div>
                            <div class="message-list" x-show="group.messages.length">
                                <template x-for="message in group.messages" :key="message.id">
                                    <button type="button" class="message-row" :data-message="message.id" :class="{unread: !message.read}" @click="openMessage(message)">
                                        <span class="avatar" :class="message.color" x-text="message.initial"></span>
                                        <span class="sender" x-text="message.sender"></span>
                                        <span class="message-copy"><strong x-text="message.subject"></strong><span x-text="message.body"></span></span>
                                        <time x-text="message.time"></time><i class="unread-dot" :class="{seen: message.read}" aria-hidden="true"></i><span class="sr-only" x-show="!message.read">خوانده‌نشده</span>
                                    </button>
                                </template>
                            </div>
                            <div class="mail-placeholder" x-show="group.key !== 'all' && !group.messages.length">
                                <x-mail-spinner x-show="loading && !pages[group.key] && group.key === 'unread'" />
                                <p class="inbox-empty" x-show="!loadError && (!loading || pages[group.key])"
                                    x-text="group.key === 'unread' ? 'نامه‌ی جدیدی ندارید' : 'هنوز نامه‌ای ندیده‌اید.'"></p>
                            </div>
                            <button class="secondary group-more" type="button" x-show="pages[group.key]?.more && !loadError"
                                :disabled="loading" @click="load(true, group.key)">نامه‌های بیشتر</button>
                        </section>
                    </template>
                    <div class="mail-placeholder folder-placeholder" x-show="!splitInbox && !visible.length">
                            <x-mail-spinner x-show="loading" />
                        <p class="inbox-empty" x-show="!loading && !loadError">نامه‌ای اینجا نیست.</p>
                    </div>
                </div>
            </template>

            <template x-if="!compose && selected">
                <section class="thread" aria-labelledby="message-heading">
                    <div class="thread-title">
                        <div class="participants" aria-hidden="true">
                            <span class="avatar" :class="selected.color" x-text="selected.initial"></span>
                            <span class="avatar account-avatar">{{ $initial }}</span>
                        </div>
                        <h1 tabindex="-1" id="message-heading" dir="auto" x-text="selected.subject"></h1>
                    </div>
                    <article class="paper reading">
                        <div class="message-meta">
                            <span class="avatar message-avatar" :class="selected.folder === 'sent' ? 'account-avatar' : selected.color"
                                aria-hidden="true" x-text="selected.folder === 'sent' ? accountInitial : selected.initial"></span>
                            <div class="message-addresses">
                                <div><strong dir="auto" x-text="selected.folder === 'sent' ? accountName : selected.sender"></strong>
                                    <bdi class="address" x-text="selected.folder === 'sent' ? accountEmail : selected.email"></bdi></div>
                                <div class="recipient">به <bdi x-text="selected.folder === 'sent' ? selected.email : accountEmail"></bdi></div>
                            </div>
                            <div class="message-tools">
                                <div class="message-options" @click.outside="if (headerMenu === 'message') closeHeaderMenu()"
                                    @keydown.escape.stop="closeHeaderMenu(true)"
                                    @focusout="if (!$el.contains($event.relatedTarget) && headerMenu === 'message') closeHeaderMenu()">
                                    <button type="button" class="more-toggle" id="message-toggle" aria-label="گزینه‌های نامه"
                                        aria-controls="message-dropdown" :aria-expanded="headerMenu === 'message'"
                                        @click="toggleHeaderMenu('message')" x-html="icons.more"></button>
                                    <div class="message-dropdown" id="message-dropdown" x-show="headerMenu === 'message'">
                                        <button type="button" @click="toggleFlag('later')"><span x-html="icons.clock"></span><span x-text="selected.later ? 'برداشتن از پاسخ بعداً' : 'پاسخ بعداً'"></span></button>
                                        <button type="button" @click="toggleFlag('saved')"><span x-html="icons.bookmark"></span><span x-text="selected.saved ? 'برداشتن از کنارگذاشته‌ها' : 'کنار بگذار'"></span></button>
                                        <button type="button" @click="toggleFlag('feed')" x-text="selected.feed ? 'برداشتن از خواندنی‌ها' : 'افزودن به خواندنی‌ها'"></button>
                                        <button type="button" @click="toggleFlag('receipts')" x-text="selected.receipts ? 'برداشتن از رسیدها' : 'افزودن به رسیدها'"></button>
                                        <button type="button" @click="if (await setFlag('read', false)) closeMessage()"><span x-html="icons.inbox"></span> نشان‌گذاری به‌عنوان خوانده‌نشده</button>
                                    </div>
                                </div>
                                <time x-text="selected.time"></time>
                            </div>
                        </div>
                        <div class="message-content">
                            <x-mail-spinner x-show="selected.loading" />
                            <template x-if="!selected.loading && selected.html">
                                <div class="message-body message-rich" dir="auto" x-html="selected.html"></div>
                            </template>
                            <template x-if="!selected.loading && !selected.html">
                                <p class="message-body" dir="auto" x-text="selected.body"></p>
                            </template>
                        </div>
                        <div class="actions" x-show="selected.attachments?.length">
                            <template x-for="file in selected.attachments ?? []" :key="file.url">
                                <a class="secondary" :href="file.url" x-text="file.name"></a>
                            </template>
                        </div>
                    </article>
                    <a class="reply-tile" :href="composeUrl + '?reply=' + encodeURIComponent(selected.id)">
                        <span x-html="icons.reply"></span> پاسخ دادن
                    </a>
                </section>
            </template>

            <template x-if="compose">
                <section class="compose-page">
                    <div class="compose-heading"><div class="heading-icon" x-html="icons.edit"></div><div><h1>نامهٔ جدید</h1><p dir="ltr">{{ $mailboxAddress }}</p></div></div>
                    <div class="composer-region">
                        <x-mail-spinner x-show="loading" />
                        <form class="paper composer" :inert="loading || Boolean(loadError)" :class="{'composer-loading': loading}" @submit.prevent="send">
                            <label class="field"><span>گیرنده</span><input type="email" required dir="ltr" placeholder="name@example.com" x-model="draft.to" @input="saveDraft"></label>
                            <label class="field"><span>موضوع</span><input required dir="auto" placeholder="موضوع نامه" x-model="draft.subject" @input="saveDraft"></label>
                            <textarea required dir="auto" aria-label="متن نامه" placeholder="متن نامه را اینجا بنویسید…" x-model="draft.body" @input="saveDraft"></textarea>
                            <div class="compose-footer">
                                <div class="actions">
                                    <button class="primary" type="submit" :disabled="busy"><span x-html="icons.send"></span><span x-text="busy ? 'در حال ارسال…' : 'ارسال نامه'"></span></button>
                                    <button class="secondary discard" type="button" @click="discardDraft" x-show="draft.to || draft.subject || draft.body">پاک کردن پیش‌نویس</button>
                                </div>
                                <small x-show="draft.to || draft.subject || draft.body">پیش‌نویس در صندوق شما ذخیره می‌شود</small>
                            </div>
                        </form>
                    </div>
                </section>
            </template>
        </main>
    </div>
    <div class="toast" role="status" :class="{'has-notice': notice}" x-text="notice"></div>
</body>
</html>
