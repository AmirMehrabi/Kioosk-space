<template x-if="!compose && screeningView">
    <section class="screening" aria-labelledby="screening-heading" :aria-busy="loading || screeningBusy">
        <header class="screening-heading">
            <nav class="screening-nav" aria-label="ناوبری بررسی فرستنده‌ها">
                <a class="screening-exit" :href="folderUrl('imbox')" @click="navigateFolder('imbox', $event)">
                    <span x-html="icons.back"></span> بازگشت به صندوق
                </a>
                <a x-show="folderKey === 'screener'" :href="folderUrl('screener-history')"
                    @click="navigateFolder('screener-history', $event)">
                    <span x-html="icons.clock"></span> سابقهٔ بررسی
                </a>
                <a x-show="folderKey === 'screener-history'" :href="folderUrl('screener')"
                    @click="navigateFolder('screener', $event)">
                    <span x-html="icons.screener"></span> فرستنده‌های جدید
                </a>
            </nav>
            <h1 id="screening-heading" tabindex="-1" x-text="folders[folderKey].title"></h1>
            <p x-show="folderKey === 'screener'">این افراد اولین بار است که به شما نامه می‌فرستند. شما تصمیم می‌گیرید از چه کسانی نامه بگیرید.</p>
        </header>

        <p class="screening-error" x-show="screeningError" role="alert" x-text="screeningError"></p>
        <div class="screening-feedback" x-show="screeningFeedback">
            <span role="status" x-text="screeningFeedback"></span>
            <button class="secondary" type="button" x-show="screeningUndo" :disabled="screeningBusy"
                @click="submitScreening(screeningUndo, null)">بازگردانی</button>
        </div>

        <div class="screening-loading" x-show="loading && !(folderKey === 'screener' ? screeningSenders : screeningHistory).length">
            <x-mail-spinner />
        </div>

        <section class="screening-queue" x-show="folderKey === 'screener' && !loadError"
            aria-labelledby="screening-queue-heading">
            <div class="screening-queue-heading" x-show="screeningSenders.length">
                <h2 id="screening-queue-heading">از این فرستنده‌ها نامه می‌خواهید؟</h2>
                <span x-text="number(screeningCount) + ' فرستنده در انتظار'"></span>
            </div>
            <p class="screening-empty" x-show="!loading && !screeningSenders.length">فرستندهٔ جدیدی در انتظار بررسی نیست.</p>
            <ul class="screening-list">
                <template x-for="(sender, index) in screeningSenders.slice(0, screeningLimit)" :key="sender.address">
                    <li class="screening-item">
                        <div class="screening-row">
                            <div class="screening-decisions" role="group" :aria-label="'تصمیم برای ' + sender.name">
                                <div class="screening-approve">
                                    <button class="screening-choice" type="button" data-screening-focus :disabled="screeningBusy"
                                        :aria-label="'بله، پذیرفتن ' + sender.name"
                                        @click="submitScreening(sender, 'imbox', index)">
                                        <span x-html="icons.thumbsUp"></span> بله
                                    </button>
                                    <details class="screening-destination" @click.outside="$el.open = false"
                                        @keydown.escape.stop="$el.open = false; $el.querySelector('summary').focus()">
                                        <summary :aria-label="'انتخاب مقصد نامه‌های ' + sender.name" x-html="icons.chevron"></summary>
                                        <div class="screening-destination-options">
                                            <template x-for="key in ['imbox', 'feed', 'receipts']" :key="key">
                                                <button type="button" :disabled="screeningBusy"
                                                    @click="$el.closest('details').open = false; submitScreening(sender, key, index)">
                                                    <span x-html="icons[folders[key].icon]"></span>
                                                    <span x-text="folders[key].title"></span>
                                                </button>
                                            </template>
                                        </div>
                                    </details>
                                </div>
                                <button class="screening-choice screening-reject" type="button" data-screening-reject :disabled="screeningBusy"
                                    :aria-label="'نه، رد کردن ' + sender.name"
                                    @click="submitScreening(sender, 'out', index)">
                                    <span x-html="icons.thumbsDown"></span> نه
                                </button>
                            </div>
                            <button class="screening-sender" type="button"
                                :aria-expanded="screeningPreview?.address === sender.address"
                                @click="screeningPreview?.address === sender.address ? screeningPreview = null : previewSender(sender)">
                                <span class="avatar" :class="['', 'green', 'amber'][sender.address.codePointAt(0) % 3]"
                                    aria-hidden="true" x-text="Array.from(sender.name)[0]"></span>
                                <span class="screening-sender-copy">
                                    <span class="screening-identity">
                                        <strong dir="auto" x-text="sender.name"></strong>
                                        <bdi dir="ltr" :title="sender.address" x-text="sender.address"></bdi>
                                    </span>
                                    <span class="screening-subject" dir="auto" x-text="sender.messages[0].subject"></span>
                                    <span class="screening-excerpt" dir="auto" x-text="sender.messages[0].excerpt"></span>
                                </span>
                                <span class="screening-preview-indicator" aria-hidden="true">
                                    <small x-show="sender.messages.length > 1" x-text="number(sender.messages.length) + ' نامه'"></small>
                                    <span x-html="icons.chevron"></span>
                                </span>
                            </button>
                        </div>
                        <div class="screening-preview" x-show="screeningPreview?.address === sender.address">
                            <div class="screening-preview-messages" x-show="sender.messages.length > 1" aria-label="نامه‌های این فرستنده">
                                <template x-for="message in sender.messages" :key="message.id">
                                    <button class="secondary" type="button" :aria-pressed="screeningPreview?.id === message.id"
                                        @click="if (screeningPreview?.id !== message.id) previewSender(sender, message)">
                                        <span dir="auto" x-text="message.subject"></span>
                                        <time :datetime="message.receivedAt" x-text="screeningTime(message.receivedAt)"></time>
                                    </button>
                                </template>
                            </div>
                            <x-mail-spinner x-show="screeningPreview?.loading" />
                            <div x-show="screeningPreview?.error" class="screening-error">
                                <p role="alert" x-text="screeningPreview?.error"></p>
                                <button class="secondary" type="button"
                                    @click="previewSender(sender, sender.messages.find(message => message.id === screeningPreview.id))">تلاش دوباره</button>
                            </div>
                            <template x-if="screeningPreview?.address === sender.address && screeningPreview?.receivedAt">
                                <article>
                                    <div class="screening-preview-meta">
                                        <span x-show="screeningPreview.to.length">به <bdi x-text="screeningPreview.to.join('، ')"></bdi></span>
                                        <span x-show="screeningPreview.cc.length">رونوشت <bdi x-text="screeningPreview.cc.join('، ')"></bdi></span>
                                        <time :datetime="screeningPreview.receivedAt" x-text="screeningTime(screeningPreview.receivedAt)"></time>
                                    </div>
                                    <p class="screening-body" dir="auto" x-text="screeningPreview.text || 'این نامه متن ندارد.'"></p>
                                </article>
                            </template>
                        </div>
                    </li>
                </template>
            </ul>
            <button class="secondary screening-more" type="button" x-show="screeningSenders.length > screeningLimit"
                @click="screeningLimit += 25">فرستنده‌های بیشتر</button>
        </section>

        <div class="screening-history" x-show="folderKey === 'screener-history' && !loadError">
            <div class="screening-filters" role="group" aria-label="نمایش تصمیم‌ها">
                <button class="secondary" type="button" :aria-pressed="screeningFilter === 'all'" @click="screeningFilter = 'all'; screeningLimit = 25">همه</button>
                <button class="secondary" type="button" :aria-pressed="screeningFilter === 'in'" @click="screeningFilter = 'in'; screeningLimit = 25">پذیرفته‌شده</button>
                <button class="secondary" type="button" :aria-pressed="screeningFilter === 'out'" @click="screeningFilter = 'out'; screeningLimit = 25">ردشده</button>
            </div>
            <p class="screening-empty" x-show="!loading && !filteredScreeningHistory.length">تصمیمی برای نمایش نیست.</p>
            <ul class="screening-list">
                <template x-for="(decision, index) in filteredScreeningHistory.slice(0, screeningLimit)" :key="decision.address">
                    <li class="screening-item screening-history-row">
                        <div class="screening-sender-copy">
                            <strong><bdi dir="ltr" x-text="decision.address"></bdi></strong>
                            <span x-text="decision.destination === 'out' ? 'ردشده' : 'پذیرفته‌شده · ' + folders[decision.destination].title"></span>
                        </div>
                        <div class="screening-history-actions">
                            <time x-show="decision.updatedAt" :datetime="decision.updatedAt" x-text="decision.updatedAt ? screeningTime(decision.updatedAt) : ''"></time>
                            <button class="secondary" type="button" data-screening-focus :disabled="screeningBusy"
                                @click="submitScreening(decision, decision.destination === 'out' ? 'imbox' : 'out', index)">
                                <span x-html="decision.destination === 'out' ? icons.thumbsUp : icons.thumbsDown"></span>
                                <span x-text="decision.destination === 'out' ? 'پذیرفتن' : 'رد کردن'"></span>
                            </button>
                        </div>
                    </li>
                </template>
            </ul>
            <button class="secondary screening-more" type="button" x-show="filteredScreeningHistory.length > screeningLimit"
                @click="screeningLimit += 25">تصمیم‌های بیشتر</button>
        </div>
    </section>
</template>
