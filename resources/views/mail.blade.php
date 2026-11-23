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
    <x-kioosk-icons />
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body x-data="mailbox" @click="leaveComposer"
    data-account-id="{{ $account->getKey() }}"
    data-account-name="{{ $account->name }}"
    data-account-email="{{ $mailboxAddress }}"
    data-account-initial="{{ $initial }}"
    data-inbox-url="{{ route('imbox', absolute: false) }}"
    data-compose-url="{{ route('compose', absolute: false) }}"
    data-screening="{{ $account->screening_enabled ? 'true' : 'false' }}"
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
                <label class="search" x-show="!compose && !screeningView" @focusin="closeHeaderMenu">
                    <span x-html="icons.search"></span>
                    <input type="search" placeholder="جست‌وجو" aria-label="جست‌وجو در نامه‌ها" x-model="query" x-ref="search" @input="search">
                </label>
            </div>

            <div class="header-brand" @focusout="if (!$el.contains($event.relatedTarget) && headerMenu === 'folders') closeHeaderMenu()">
                <button type="button" class="brand folder-toggle" id="folder-toggle" aria-label="کیوسک، پوشه‌های نامه"
                    aria-controls="folder-dropdown" :aria-expanded="headerMenu === 'folders'" @click="toggleHeaderMenu('folders')">
                    <x-kioosk-brand />
                    <span class="brand-chevron" x-html="icons.chevron"></span>
                </button>
                <nav class="folder-dropdown" id="folder-dropdown" aria-label="پوشه‌های نامه" x-show="headerMenu === 'folders'">
                    <template x-for="(folder, key) in navigationFolders" :key="key">
                        <a :href="folderUrl(key)" class="nav-item" :class="{active: !compose && folderKey === key}"
                            @click="navigateFolder(key, $event)" :aria-current="!compose && folderKey === key ? 'page' : null">
                            <span x-html="icons[folder.icon]"></span><span x-text="folder.title"></span>
                            <small x-show="key === 'imbox' && unread" x-text="number(unread)"></small>
                            <small x-show="key === 'screener' && screeningCount" x-text="number(screeningCount)"></small>
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
                        @if ($account->screening_enabled)
                            <a class="secondary" :href="folderUrl('screener-history')" @click="navigateFolder('screener-history', $event)">سابقهٔ بررسی فرستنده‌ها</a>
                        @endif
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
            <div class="empty" x-show="loadError" role="alert"><p x-text="loadError"></p><button class="secondary" @click="compose ? openComposer() : reloadMailbox()">تلاش دوباره</button></div>
            @if ($account->screening_enabled)
                @include('mail.screener')
            @endif
            <template x-if="!compose && !selected && !screeningView">
                <div>
                    <section class="intro" :class="{'hero': folderKey === 'imbox' && !query}">
                        <div>
                            <p class="eyebrow" x-show="folderKey === 'imbox' && !query"><span></span> صندوق {{ $account->name }}</p>
                            <h1 x-text="query ? 'نتایج جست‌وجو' : folders[folderKey].title"></h1>
                            <p class="intro-description" x-text="query ? number(visible.length) + ' نامه پیدا شد' : folders[folderKey].description"></p>
                        </div>
                    </section>
                    @if ($account->screening_enabled)
                        <a class="screening-entry" x-show="splitInbox && screeningCount" :href="folderUrl('screener')"
                            @click="navigateFolder('screener', $event)">
                            <span x-html="icons.screener"></span>
                            <span x-text="'بررسی ' + number(screeningCount) + ' فرستندهٔ جدید'"></span>
                        </a>
                    @endif
                    <section class="bulk-bar" x-ref="bulkBar" x-show="checkedIds.length" aria-label="عملیات نامه‌های انتخاب‌شده">
                        <div class="bulk-bar-heading">
                            <strong role="status" x-text="number(checkedIds.length) + ' نامه انتخاب شده'"></strong>
                            <button class="secondary" type="button" :disabled="bulkBusy" @click="clearBulkSelection(true)">لغو انتخاب</button>
                        </div>
                        <form @submit.prevent="submitBulkAction()">
                            <fieldset class="bulk-controls" :disabled="bulkBusy || loading">
                                <select aria-label="عملیات" x-model="bulkAction" x-ref="bulkActionMenu" @change="bulkLabel = ''">
                                    <option value="">انتخاب عملیات</option>
                                    <optgroup label="وضعیت خواندن">
                                        <option value="read">نشان‌گذاری به‌عنوان خوانده‌شده</option>
                                        <option value="unread">نشان‌گذاری به‌عنوان خوانده‌نشده</option>
                                    </optgroup>
                                    <optgroup label="دسته‌بندی">
                                        <option value="later">افزودن به پاسخ بعداً</option>
                                        <option value="unlater">حذف از پاسخ بعداً</option>
                                        <option value="save">افزودن به کنارگذاشته‌ها</option>
                                        <option value="unsave">حذف از کنارگذاشته‌ها</option>
                                        <option value="label">افزودن برچسب</option>
                                        <option value="unlabel" :disabled="!bulkLabels.length">حذف برچسب</option>
                                    </optgroup>
                                    <option value="move">انتقال به پوشه</option>
                                    <option value="trash" :disabled="folderKey === 'trash' && !query.trim()">انتقال به زباله‌دان</option>
                                </select>
                                <template x-if="bulkAction === 'label'">
                                    <input type="text" aria-label="برچسب جدید" placeholder="برچسب جدید" dir="auto" maxlength="25" required x-model="bulkLabel">
                                </template>
                                <template x-if="bulkAction === 'unlabel'">
                                    <select aria-label="برچسب برای حذف" x-model="bulkLabel" required>
                                        <option value="">انتخاب برچسب</option>
                                        <template x-for="label in bulkLabels" :key="label">
                                            <option :value="label" x-text="label" dir="auto"></option>
                                        </template>
                                    </select>
                                </template>
                                <template x-if="bulkAction === 'move'">
                                    <select aria-label="پوشهٔ مقصد" x-model="bulkDestination">
                                        <option value="imbox">نامه‌های مهم</option>
                                        <option value="feed">خواندنی‌ها</option>
                                        <option value="receipts">رسیدها</option>
                                    </select>
                                </template>
                                <button class="primary" type="submit" :disabled="!bulkActionReady" x-text="bulkBusy ? 'در حال انجام…' : 'اعمال'"></button>
                            </fieldset>
                        </form>
                        <p class="bulk-feedback" x-show="bulkFeedback" x-text="bulkFeedback"></p>
                    </section>
                    <dialog class="paper compose-dialog" x-ref="bulkTrashDialog" aria-labelledby="bulk-trash-title">
                        <h2 id="bulk-trash-title">انتقال به زباله‌دان</h2>
                        <p x-text="number(checkedIds.length) + ' نامه به زباله‌دان منتقل شود؟'"></p>
                        <form class="actions" @submit.prevent="$refs.bulkTrashDialog.close(); applyBulkAction()">
                            <button class="secondary" type="button" autofocus @click="$refs.bulkTrashDialog.close()">بازگشت</button>
                            <button class="primary" type="submit">انتقال به زباله‌دان</button>
                        </form>
                    </dialog>
                    <template x-for="group in groups" :key="group.key">
                        <section class="message-group" :aria-label="group.label">
                            <div class="group-title" :class="{'group-title-rule': splitInbox}"><h2 x-text="group.label"></h2><span x-show="group.messages.length" x-text="number(group.messages.length)"></span></div>
                            <div class="mail-section-content">
                                <x-mail-spinner x-show="loading && !pages[group.key]" />
                                <div class="message-list" x-show="group.messages.length">
                                    <template x-for="message in group.messages" :key="message.id">
                                        <div class="message-row" :class="{unread: !message.read, 'selection-mode': checkedIds.length, 'is-checked': checkedIds.includes(message.id)}">
                                            <label class="message-select">
                                                <span class="avatar" :class="message.color" x-text="message.initial" aria-hidden="true"></span>
                                                <input type="checkbox" :aria-label="'انتخاب نامه: ' + message.subject" :checked="checkedIds.includes(message.id)"
                                                    :disabled="bulkBusy" @change="toggleBulkSelection(message.id, $event.target.checked)">
                                            </label>
                                            <button type="button" class="message-open" :data-message="message.id" :aria-disabled="bulkBusy"
                                                @click="if (!bulkBusy) openMessage(message)">
                                                <span class="message-copy">
                                                    <strong class="message-subject" dir="auto" x-text="message.subject"></strong>
                                                    <span class="message-summary"><bdi class="message-sender" x-text="message.sender"></bdi><span x-show="message.body"> · <bdi x-text="message.body"></bdi></span></span>
                                                </span>
                                                <time :datetime="message.receivedAt" x-text="message.time"></time>
                                                <i class="unread-dot" :class="{seen: message.read}" aria-hidden="true"></i><span class="sr-only" x-show="!message.read">خوانده‌نشده</span>
                                            </button>
                                        </div>
                                    </template>
                                </div>
                                <p class="inbox-empty" x-show="group.key !== 'all' && !group.messages.length && !loadError && (!loading || pages[group.key])"
                                    x-text="group.key === 'unread' ? 'نامه‌ی جدیدی ندارید' : 'هنوز نامه‌ای ندیده‌اید.'"></p>
                            </div>
                            <button class="secondary group-more" type="button" x-show="pages[group.key]?.more && !loadError"
                                :disabled="loading || bulkBusy" @click="load(true, group.key)">نامه‌های بیشتر</button>
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
                                        :disabled="selected.loading" @click="toggleHeaderMenu('message')" x-html="icons.more"></button>
                                    <div class="message-dropdown" id="message-dropdown" x-show="headerMenu === 'message'">
                                        <a :href="composeUrl + '?forward=' + encodeURIComponent(selected.id)"><span x-html="icons.forward"></span> بازارسال</a>
                                        <button type="button" @click="openThreadAction('label')"><span x-html="icons.label"></span> برچسب‌ها</button>
                                        <button type="button" @click="openThreadAction('move')"><span x-html="icons.move"></span> انتقال به پوشه</button>
                                        <button type="button" @click="openThreadAction('unread')"><span x-html="icons.inbox"></span> نشان‌گذاری به‌عنوان خوانده‌نشده</button>
                                        <button type="button" class="trash-action" x-show="folderKey !== 'trash'" @click="openThreadAction('trash')"><span x-html="icons.trash"></span> انتقال به زباله‌دان</button>
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
                    <ul class="thread-labels" x-show="selected.labels?.length" aria-label="برچسب‌ها">
                        <template x-for="label in selected.labels ?? []" :key="label">
                            <li dir="auto" x-text="label"></li>
                        </template>
                    </ul>
                    <dialog class="paper compose-dialog thread-dialog" x-ref="threadDialog" aria-labelledby="thread-action-title" @cancel="if (threadBusy) $event.preventDefault()">
                        <h2 id="thread-action-title" x-text="{label: 'برچسب‌های نامه', move: 'انتقال نامه', unread: 'نامه خوانده‌نشده شود؟', trash: 'نامه به زباله‌دان منتقل شود؟'}[threadAction]"></h2>
                        <form @submit.prevent="applyThreadAction()">
                            <fieldset :disabled="threadBusy">
                                <template x-if="threadAction === 'label'">
                                    <div>
                                        <template x-for="label in selected.labels ?? []" :key="label">
                                            <button type="button" class="secondary" @click="applyThreadAction(label)" :aria-label="'حذف برچسب ' + label"><bdi x-text="label"></bdi> ×</button>
                                        </template>
                                        <label for="thread-label">برچسب جدید</label>
                                        <input id="thread-label" dir="auto" maxlength="25" required x-model="labelInput">
                                    </div>
                                </template>
                                <template x-if="threadAction === 'move'">
                                    <div>
                                        <label for="thread-destination">پوشهٔ مقصد</label>
                                        <select id="thread-destination" x-model="destination">
                                            <option value="imbox">نامه‌های مهم</option>
                                            <option value="feed">خواندنی‌ها</option>
                                            <option value="receipts">رسیدها</option>
                                        </select>
                                    </div>
                                </template>
                                <p x-show="threadError" role="alert" x-text="threadError"></p>
                                <div class="actions">
                                    <button type="button" class="secondary" autofocus @click="$refs.threadDialog.close()">بازگشت</button>
                                    <button class="primary" type="submit" x-text="threadAction === 'label' ? 'افزودن برچسب' : 'تأیید'"></button>
                                </div>
                            </fieldset>
                        </form>
                    </dialog>
                    <div class="thread-replies">
                        <a class="reply-tile" :href="composeUrl + '?reply=' + encodeURIComponent(selected.id)">
                            <span x-html="icons.reply"></span> پاسخ دادن
                        </a>
                        <a class="reply-tile" x-show="selected.replyAll?.length > 1" :href="composeUrl + '?reply=' + encodeURIComponent(selected.id) + '&amp;all=1'">
                            <span x-html="icons.reply"></span> پاسخ به همه
                        </a>
                    </div>
                </section>
            </template>

            <template x-if="compose">
                <section class="compose-page">
                    <div class="compose-heading"><div class="heading-icon" x-html="icons.edit"></div><div><h1 x-text="reply ? (replyAll ? 'پاسخ به همه' : 'پاسخ به نامه') : (forward ? 'بازارسال نامه' : 'نامهٔ جدید')"></h1><p dir="ltr">{{ $mailboxAddress }}</p></div></div>
                    <div class="composer-region">
                        <a class="secondary recover-draft" x-show="hasLegacyDraft && !reply && !forward" :href="composeUrl + '?recover=1'">باز کردن پیش‌نویس قبلی</a>
                        <x-mail-spinner x-show="loading" />
                        <form class="paper composer" :inert="loading || Boolean(loadError)" :class="{'composer-loading': loading}" @submit.prevent="send">
                            <fieldset :disabled="busy">
                                <p class="forward-note" x-show="draft.forward">متن و پیوست‌های نامهٔ اصلی همراه بازارسال فرستاده می‌شوند.</p>
                                @foreach (['to' => 'گیرنده', 'cc' => 'رونوشت', 'bcc' => 'رونوشت پنهان'] as $field => $label)
                                    <div class="field recipient-field" x-show="{{ $field === 'to' ? 'true' : $field.'Open' }}">
                                        <label for="recipient-{{ $field }}">{{ $label }}</label>
                                        <div class="recipient-control">
                                            <div class="recipient-chips" dir="ltr">
                                                <template x-for="address in draft.{{ $field }}" :key="address">
                                                    <span class="recipient-chip">
                                                        <bdi x-text="address"></bdi>
                                                        <button type="button" :aria-label="'حذف ' + address" @click="removeRecipient('{{ $field }}', address)">×</button>
                                                    </span>
                                                </template>
                                                <input id="recipient-{{ $field }}" @if ($field === 'to') x-ref="recipientTo" @endif
                                                    type="text" inputmode="email" autocomplete="off" spellcheck="false"
                                                    placeholder="name@example.com" x-model="recipientInputs.{{ $field }}"
                                                    :aria-invalid="Boolean(recipientErrors.{{ $field }})"
                                                    aria-describedby="recipient-error-{{ $field }}"
                                                    @keydown.enter.prevent="commitRecipients('{{ $field }}')"
                                                    @keydown.comma.prevent="commitRecipients('{{ $field }}')"
                                                    @paste="pasteRecipients('{{ $field }}', $event)"
                                                    @blur="commitRecipients('{{ $field }}')">
                                            </div>
                                            <small id="recipient-error-{{ $field }}" class="recipient-error" x-show="recipientErrors.{{ $field }}" role="alert" x-text="recipientErrors.{{ $field }}"></small>
                                        </div>
                                        @if ($field === 'to')
                                            <div class="recipient-options">
                                                <button type="button" x-show="!ccOpen" @click="ccOpen = true; $nextTick(() => document.getElementById('recipient-cc').focus())">رونوشت</button>
                                                <button type="button" x-show="!bccOpen" @click="bccOpen = true; $nextTick(() => document.getElementById('recipient-bcc').focus())">رونوشت پنهان</button>
                                            </div>
                                        @endif
                                    </div>
                                @endforeach
                                <label class="field"><span>موضوع</span><input required dir="auto" maxlength="300" placeholder="موضوع نامه" x-model="draft.subject" @input="saveDraft"></label>
                                <div class="format-toolbar" role="group" aria-label="قالب‌بندی نامه" @mousedown.prevent="rememberSelection">
                                    <button type="button" aria-label="پررنگ" @click="format('bold')"><strong>B</strong></button>
                                    <button type="button" aria-label="مورب" @click="format('italic')"><em>I</em></button>
                                    <button type="button" @click="openLinkEditor">پیوند</button>
                                    <button type="button" @click="format('insertUnorderedList')">فهرست</button>
                                    <button type="button" @click="format('formatBlock', 'blockquote')">نقل‌قول</button>
                                </div>
                                <div class="compose-editor message-rich" x-ref="editor" dir="auto" role="textbox" aria-multiline="true" aria-label="متن نامه"
                                    :contenteditable="!busy" :data-empty="editorEmpty" data-placeholder="متن نامه را اینجا بنویسید…"
                                    @input="editorChanged" @focusout="rememberSelection" @paste.prevent="pasteEditor" @drop.prevent></div>
                                <details class="reply-quote" x-show="draft.quote">
                                    <summary>نمایش متن نامهٔ اصلی</summary>
                                    <p x-text="draft.quote?.attribution"></p>
                                    <blockquote class="message-rich" dir="auto" x-html="draft.quote?.html ?? ''"></blockquote>
                                </details>
                                <div class="compose-attachments" @dragover.prevent @drop.prevent="addFiles($event.dataTransfer.files)">
                                    <div class="attachments-toolbar">
                                        <input type="file" multiple x-ref="filePicker" hidden @change="addFiles($event.target.files); $event.target.value = ''">
                                        <button class="secondary" type="button" :disabled="Boolean(uploading)" @click="$refs.filePicker.click()">
                                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><path d="m8 13 6-6a2 2 0 0 1 3 3l-8 8a4 4 0 0 1-6-6L12 3a6 6 0 0 1 8 8l-9 9"/></svg>
                                            افزودن پیوست
                                        </button>
                                        <small>فایل‌ها را اینجا رها کنید. هر فایل تا ۸ مگابایت.</small>
                                    </div>
                                    <template x-for="file in draft.files" :key="file.id">
                                        <div class="compose-file"><bdi x-text="file.name"></bdi><small x-text="number(Math.ceil(file.size / 1024)) + ' کیلوبایت'"></small>
                                            <button class="secondary" type="button" :aria-label="'حذف ' + file.name" @click="removeFile(file.id)">حذف</button></div>
                                    </template>
                                    <div class="compose-file" x-show="uploading">
                                        <bdi x-text="uploading?.name"></bdi>
                                        <progress max="100" :value="uploading?.progress ?? 0" aria-label="پیشرفت بارگذاری"></progress>
                                        <button class="secondary" type="button" @click="uploadRequest?.abort()">لغو</button>
                                    </div>
                                </div>
                                <div class="compose-footer">
                                    <div class="actions">
                                        <button class="primary" type="submit" :disabled="busy || Boolean(uploading)"><span x-html="icons.send"></span><span x-text="busy ? 'در حال ارسال…' : 'ارسال نامه'"></span></button>
                                        <button class="secondary discard" type="button" @click="$refs.discard.showModal()" x-show="hasDraft" :disabled="Boolean(uploading)">پاک کردن پیش‌نویس</button>
                                    </div>
                                    <small role="status" x-text="draftStatus"></small>
                                </div>
                            </fieldset>
                        </form>
                        <dialog class="paper compose-dialog link-dialog" x-ref="linkDialog" aria-labelledby="link-heading">
                            <h2 id="link-heading">افزودن پیوند</h2>
                            <form @submit.prevent="insertLink">
                                <label for="editor-link">نشانی پیوند</label>
                                <input id="editor-link" dir="ltr" type="text" inputmode="url" placeholder="https://example.com"
                                    x-model="editorLink" autofocus required>
                                <small class="recipient-error" x-show="editorLinkError" role="alert" x-text="editorLinkError"></small>
                                <div class="actions">
                                    <button class="primary">افزودن</button>
                                    <button class="secondary" type="button" @click="$refs.linkDialog.close()">بازگشت</button>
                                </div>
                            </form>
                        </dialog>
                        <dialog class="paper compose-dialog" x-ref="discard" aria-labelledby="discard-heading">
                            <h2 id="discard-heading">پیش‌نویس پاک شود؟</h2>
                            <p>متن، گیرنده‌ها و پیوست‌های این پیش‌نویس پاک می‌شوند.</p>
                            <form method="dialog" class="actions">
                                <button class="secondary" autofocus>بازگشت</button>
                                <button class="primary" @click="discardDraft">پاک کردن پیش‌نویس</button>
                            </form>
                        </dialog>
                    </div>
                </section>
            </template>
        </main>
    </div>
    <div class="toast" role="status" :class="{'has-notice': notice}" x-text="notice"></div>
</body>
</html>
