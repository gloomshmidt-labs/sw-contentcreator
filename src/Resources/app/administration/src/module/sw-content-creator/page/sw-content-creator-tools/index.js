import template from './sw-content-creator-tools.html.twig';
import languageResolveMixin from '../../mixin/language-resolve.mixin';
import busyMixin from '../../mixin/busy.mixin';

const { Component, Mixin } = Shopware;

/**
 * SEO-Werkzeuge: eigenständige Wartungs-/Diagnose-Aktionen ohne Bezug zur
 * Batch-Auswahl (Usability-Review: von der Stapelverarbeitung getrennt).
 */
Component.register('sw-content-creator-tools', {
    template,

    inject: ['contentCreatorApiService', 'repositoryFactory', 'systemConfigApiService'],

    mixins: [Mixin.getByName('notification'), languageResolveMixin, busyMixin],

    data() {
        return {
            entityType: 'product',
            lbResult: null,
            lbBusy: false,
            lbProgress: null,
            cannibalResult: null,
            cannibalBusy: false,
            renameItems: null,
            renameTotal: 0,
            renameWithoutAlt: 0,
            renameBusy: false,
            showRenameConfirm: false,
            redirectFileConfigured: false,
            pendingRenameItems: null,
        };
    },

    created() {
        this.systemConfigApiService.getValues('ContentCreator.config')
            .then((values) => {
                this.redirectFileConfigured = !!(values['ContentCreator.config.redirectFile'] || '').trim();
            })
            .catch(() => { this.redirectFileConfigured = false; });
    },

    computed: {
        effectiveLanguageId() {
            return this.languageId || Shopware.Context.api.languageId;
        },
        entityTypeOptions() {
            return [
                { value: 'product', label: this.$tc('sw-content-creator.generator.product') },
                { value: 'category', label: this.$tc('sw-content-creator.generator.category') },
            ];
        },
        langOptions() {
            const labels = { de: 'Deutsch', en: 'English' };

            return this.availableLangs.map((value) => ({ value, label: labels[value] }));
        },
    },

    methods: {
        onLangChange(value) {
            this.lang = value;
            this.resolveLanguageId(value);
        },

        scanCannibalization() {
            this.runBusy('cannibalBusy', () => this.contentCreatorApiService.cannibalization({
                entityType: this.entityType,
                languageId: this.effectiveLanguageId,
            })
                .then((res) => { this.cannibalResult = res; }));
        },

        scanMediaRenames() {
            this.runBusy('renameBusy', () => this.contentCreatorApiService.mediaRenameScan({
                languageId: this.effectiveLanguageId,
            })
                .then((res) => {
                    this.renameItems = res.items || [];
                    this.renameTotal = res.total || this.renameItems.length;
                    this.renameWithoutAlt = res.withoutAlt || 0;
                }));
        },

        confirmRename() {
            this.pendingRenameItems = this.renameItems || [];
            this.showRenameConfirm = true;
        },

        // Kontrollierter Einzel-Test: nur dieses eine Bild umbenennen
        confirmRenameSingle(item) {
            this.pendingRenameItems = [item];
            this.showRenameConfirm = true;
        },

        applyMediaRenames() {
            this.showRenameConfirm = false;
            const pending = this.pendingRenameItems || [];
            const items = pending.map((i) => ({
                mediaId: i.mediaId,
                newName: i.suggestedName,
                currentName: i.currentName,
            }));
            if (!items.length) {
                return;
            }
            this.runBusy('renameBusy', () => this.contentCreatorApiService.mediaRenameApply(items)
                .then((res) => {
                    this.createNotificationSuccess({
                        message: this.$tc('sw-content-creator.rename.done', { renamed: res.renamed, errors: (res.errors || []).length }, res.renamed),
                    });
                    const renamedIds = new Set(items.map((i) => i.mediaId));
                    this.renameItems = (this.renameItems || []).filter((i) => !renamedIds.has(i.mediaId));
                    this.pendingRenameItems = null;
                }));
        },

        writeRedirectFileNow() {
            this.contentCreatorApiService.mediaRenameWriteFile()
                .then((res) => {
                    this.createNotificationSuccess({ message: this.$tc('sw-content-creator.rename.fileWritten') + ' ' + (res.path || '') });
                })
                .catch((err) => this.notifyApiError(err));
        },

        downloadRedirects() {
            this.contentCreatorApiService.mediaRenameExport()
                .then((content) => {
                    const blob = new Blob([content], { type: 'text/plain' });
                    const link = document.createElement('a');
                    link.href = URL.createObjectURL(blob);
                    link.download = 'contentcreator-media-redirects.conf';
                    link.click();
                    URL.revokeObjectURL(link.href);
                })
                .catch((err) => this.notifyApiError(err));
        },

        scanLineBreaks() {
            this.runBusy('lbBusy', () => this.contentCreatorApiService.scanLineBreaks(this.effectiveLanguageId)
                .then((res) => { this.lbResult = res; }));
        },

        removeLineBreakEntry(categoryId) {
            this.lbResult = {
                ...this.lbResult,
                affected: (this.lbResult?.affected || []).filter((a) => a.id !== categoryId),
            };
        },

        fixLineBreak(categoryId) {
            this.runBusy('lbBusy', () => this.contentCreatorApiService.fixLineBreaks(categoryId, this.effectiveLanguageId)
                .then((res) => {
                    this.createNotificationSuccess({
                        message: this.$tc('sw-content-creator.linebreaks.fixed', { fixed: res.fixed }, res.fixed),
                    });
                    this.removeLineBreakEntry(categoryId);
                }));
        },

        fixAllLineBreaks() {
            const affected = [...(this.lbResult?.affected || [])];
            this.runBusy('lbBusy', async () => {
                let total = 0;
                this.lbProgress = { done: 0, total: affected.length };
                for (const entry of affected) {
                    // sequenziell, um die API nicht zu fluten
                    // eslint-disable-next-line no-await-in-loop
                    const res = await this.contentCreatorApiService.fixLineBreaks(entry.id, this.effectiveLanguageId);
                    total += res.fixed || 0;
                    this.removeLineBreakEntry(entry.id);
                    this.lbProgress = { done: this.lbProgress.done + 1, total: affected.length };
                }
                this.lbProgress = null;
                this.createNotificationSuccess({
                    message: this.$tc('sw-content-creator.linebreaks.fixed', { fixed: total }, total),
                });
            });
        },
    },
});
