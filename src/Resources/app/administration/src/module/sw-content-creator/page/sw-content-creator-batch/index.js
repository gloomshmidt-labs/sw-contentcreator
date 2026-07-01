import template from './sw-content-creator-batch.html.twig';
import { estimateCost, formatCost } from '../../../content-creator/engine/pricing';
import languageResolveMixin from '../../mixin/language-resolve.mixin';

const { Component, Mixin } = Shopware;

Component.register('sw-content-creator-batch', {
    template,

    inject: ['contentCreatorApiService', 'repositoryFactory'],

    mixins: [Mixin.getByName('notification'), languageResolveMixin],

    data() {
        return {
            entityType: 'product',
            selectedIds: [],
            selectedTypes: [],
            mode: 'create',
            job: null,
            polling: null,
            isStarting: false,
            lbResult: null,
            lbBusy: false,
            dryRun: false,
            isCommitting: false,
            gapResult: null,
            gapBusy: false,
            cannibalResult: null,
            cannibalBusy: false,
            freshnessResult: null,
            freshnessBusy: false,
            report: null,
            reportBusy: false,
        };
    },

    computed: {
        repository() {
            return this.repositoryFactory.create(this.entityType === 'manufacturer' ? 'product_manufacturer' : this.entityType);
        },
        entityTypeOptions() {
            return [
                { value: 'product', label: this.$tc('sw-content-creator.generator.product') },
                { value: 'category', label: this.$tc('sw-content-creator.generator.category') },
                { value: 'media', label: this.$tc('sw-content-creator.batch.media') },
                { value: 'sales_channel', label: this.$tc('sw-content-creator.generator.homepage') },
                { value: 'manufacturer', label: this.$tc('sw-content-creator.generator.manufacturer') },
            ];
        },
        langOptions() {
            return [
                { value: 'de', label: 'Deutsch' },
                { value: 'en', label: 'English' },
            ];
        },
        modeOptions() {
            return [
                { value: 'create', label: this.$tc('sw-content-creator.generator.modeCreate') },
                { value: 'optimize', label: this.$tc('sw-content-creator.generator.modeOptimize') },
            ];
        },
        typeOptions() {
            if (this.entityType === 'product') {
                return ['product_description', 'product_meta', 'faq'];
            }
            if (this.entityType === 'category') {
                return ['category_teaser', 'category_detail', 'category_meta', 'faq'];
            }
            if (this.entityType === 'sales_channel') {
                return ['home_meta'];
            }
            if (this.entityType === 'manufacturer') {
                return ['manufacturer_description'];
            }

            return ['media_alt'];
        },
        progressDone() {
            if (!this.job) {
                return 0;
            }

            return this.job.processed + this.job.failed + (this.job.rejected || 0);
        },
        progressPercent() {
            if (!this.job || !this.job.total) {
                return 0;
            }

            return Math.round((this.progressDone / this.job.total) * 100);
        },
        isRunning() {
            return this.job && ['open', 'running'].includes(this.job.status);
        },
        jobCost() {
            if (!this.job || !(this.job.inputTokens || this.job.outputTokens)) {
                return '';
            }
            const cost = estimateCost(this.job.model, this.job.inputTokens || 0, this.job.outputTokens || 0);

            return cost === null ? '' : formatCost(cost);
        },
    },

    methods: {
        onLangChange(value) {
            this.lang = value;
            this.selectedIds = [];
            this.resolveLanguageId(value);
        },

        onEntityTypeChange(value) {
            this.entityType = value;
            this.selectedIds = [];
            this.selectedTypes = [];
        },

        onIdsChange(ids) {
            this.selectedIds = ids || [];
        },

        typeLabel(type) {
            return this.$tc(`sw-content-creator.types.${type}`);
        },

        setType(type, checked) {
            const index = this.selectedTypes.indexOf(type);
            if (checked && index === -1) {
                this.selectedTypes.push(type);
            } else if (!checked && index !== -1) {
                this.selectedTypes.splice(index, 1);
            }
        },

        startBatch() {
            if (!this.selectedIds.length || !this.selectedTypes.length) {
                this.createNotificationWarning({ message: this.$tc('sw-content-creator.batch.needSelection') });
                return;
            }
            this.isStarting = true;
            this.contentCreatorApiService.startBatch({
                entityType: this.entityType,
                ids: this.selectedIds,
                types: this.selectedTypes,
                languageId: this.languageId || Shopware.Context.api.languageId,
                mode: this.mode,
                dryRun: this.dryRun,
            })
                .then((res) => {
                    this.job = { id: res.jobId, total: res.total, processed: 0, failed: 0, rejected: 0, status: 'running', dryRun: this.dryRun };
                    this.startPolling();
                })
                .catch((err) => {
                    this.createNotificationError({ message: err?.response?.data?.error || err.message });
                })
                .finally(() => { this.isStarting = false; });
        },

        startPolling() {
            this.stopPolling();
            this.polling = setInterval(() => {
                if (!this.job) {
                    return;
                }
                this.contentCreatorApiService.batchStatus(this.job.id)
                    .then((res) => {
                        this.job = res.job;
                        if (['done', 'failed', 'done_with_errors'].includes(res.job.status)) {
                            this.stopPolling();
                            this.createNotificationSuccess({ message: this.$tc('sw-content-creator.batch.finished') });
                        }
                    })
                    .catch(() => {});
            }, 2000);
        },

        stopPolling() {
            if (this.polling) {
                clearInterval(this.polling);
                this.polling = null;
            }
        },

        commitDryRun() {
            if (!this.job?.id) {
                return;
            }
            this.isCommitting = true;
            this.contentCreatorApiService.commitBatch(this.job.id)
                .then((res) => {
                    this.createNotificationSuccess({
                        message: this.$tc('sw-content-creator.batch.committed', res.applied, { applied: res.applied, errors: res.errors }),
                    });
                    this.job = { ...this.job, committed: true };
                })
                .catch((err) => {
                    this.createNotificationError({ message: err?.response?.data?.error || err.message });
                })
                .finally(() => { this.isCommitting = false; });
        },

        scanGaps() {
            this.gapBusy = true;
            this.contentCreatorApiService.gaps({
                entityType: this.entityType,
                languageId: this.languageId || Shopware.Context.api.languageId,
            })
                .then((res) => { this.gapResult = res.gaps; })
                .catch((err) => {
                    this.createNotificationError({ message: err?.response?.data?.error || err.message });
                })
                .finally(() => { this.gapBusy = false; });
        },

        gapLabel(key) {
            return this.$tc(`sw-content-creator.gaps.${key}`);
        },

        scanFreshness() {
            this.freshnessBusy = true;
            this.contentCreatorApiService.freshness({
                entityType: ['product', 'category', 'manufacturer'].includes(this.entityType) ? this.entityType : 'product',
                languageId: this.languageId || Shopware.Context.api.languageId,
            })
                .then((res) => { this.freshnessResult = res; })
                .catch((err) => {
                    this.createNotificationError({ message: err?.response?.data?.error || err.message });
                })
                .finally(() => { this.freshnessBusy = false; });
        },

        useFreshnessSelection() {
            const ids = [
                ...(this.freshnessResult?.changedSince || []),
                ...(this.freshnessResult?.aging || []),
            ].map((i) => i.id);
            if (!ids.length) {
                return;
            }
            this.selectedIds = [...new Set(ids)];
            this.mode = 'optimize';
            this.createNotificationSuccess({
                message: this.$tc('sw-content-creator.gaps.selected', ids.length, { count: ids.length }),
            });
        },

        scanCannibalization() {
            this.cannibalBusy = true;
            this.contentCreatorApiService.cannibalization({
                entityType: this.entityType === 'category' ? 'category' : 'product',
                languageId: this.languageId || Shopware.Context.api.languageId,
            })
                .then((res) => { this.cannibalResult = res; })
                .catch((err) => {
                    this.createNotificationError({ message: err?.response?.data?.error || err.message });
                })
                .finally(() => { this.cannibalBusy = false; });
        },

        // Lücken-Ergebnis direkt als Batch-Auswahl übernehmen (Modus: Neu erstellen)
        useGapSelection(key) {
            const gap = this.gapResult?.[key];
            if (!gap?.ids?.length) {
                return;
            }
            this.selectedIds = [...gap.ids];
            this.mode = 'create';
            this.createNotificationSuccess({
                message: this.$tc('sw-content-creator.gaps.selected', gap.ids.length, { count: gap.ids.length }),
            });
        },

        async runQualityReport() {
            this.reportBusy = true;
            const items = [];
            let offset = 0;
            let scanned = 0;
            try {
                for (;;) {
                    // eslint-disable-next-line no-await-in-loop
                    const page = await this.contentCreatorApiService.qualityReport({
                        entityType: this.entityType === 'category' ? 'category' : 'product',
                        languageId: this.languageId || Shopware.Context.api.languageId,
                        offset,
                    });
                    items.push(...page.items);
                    scanned += page.scanned;
                    offset += page.scanned;
                    if (page.done) {
                        break;
                    }
                }
                items.sort((a, b) => b.score - a.score);
                this.report = { items: items.slice(0, 50), total: items.length, scanned };
            } catch (err) {
                this.createNotificationError({ message: err?.response?.data?.error || err.message });
            } finally {
                this.reportBusy = false;
            }
        },

        // 1-Klick-Fix: Report-Eintrag direkt im Generator öffnen (Modus: Optimieren)
        openInGenerator(id) {
            this.$router.push({
                name: 'sw.content.creator.generator',
                query: {
                    entityType: this.entityType === 'category' ? 'category' : 'product',
                    id,
                    mode: 'optimize',
                    lang: this.lang,
                },
            });
        },

        // Schlechteste Texte als Auswahl übernehmen (Modus: Bestand optimieren)
        useWorstSelection() {
            const ids = (this.report?.items || []).slice(0, 25).map((i) => i.id);
            if (!ids.length) {
                return;
            }
            this.selectedIds = ids;
            this.mode = 'optimize';
            this.createNotificationSuccess({
                message: this.$tc('sw-content-creator.gaps.selected', ids.length, { count: ids.length }),
            });
        },

        scanLineBreaks() {
            this.lbBusy = true;
            this.contentCreatorApiService.scanLineBreaks(this.languageId || Shopware.Context.api.languageId)
                .then((res) => { this.lbResult = res; })
                .catch((err) => {
                    this.createNotificationError({ message: err?.response?.data?.error || err.message });
                })
                .finally(() => { this.lbBusy = false; });
        },

        fixLineBreak(categoryId) {
            this.lbBusy = true;
            this.contentCreatorApiService.fixLineBreaks(categoryId, this.languageId || Shopware.Context.api.languageId)
                .then((res) => {
                    this.createNotificationSuccess({
                        message: this.$tc('sw-content-creator.linebreaks.fixed', res.fixed, { fixed: res.fixed }),
                    });
                    this.lbResult = {
                        ...this.lbResult,
                        affected: (this.lbResult?.affected || []).filter((a) => a.id !== categoryId),
                    };
                })
                .catch((err) => {
                    this.createNotificationError({ message: err?.response?.data?.error || err.message });
                })
                .finally(() => { this.lbBusy = false; });
        },

        async fixAllLineBreaks() {
            const affected = [...(this.lbResult?.affected || [])];
            this.lbBusy = true;
            let total = 0;
            try {
                for (const entry of affected) {
                    // sequenziell, um die API nicht zu fluten
                    // eslint-disable-next-line no-await-in-loop
                    const res = await this.contentCreatorApiService.fixLineBreaks(entry.id, this.languageId || Shopware.Context.api.languageId);
                    total += res.fixed || 0;
                    this.lbResult = {
                        ...this.lbResult,
                        affected: (this.lbResult?.affected || []).filter((a) => a.id !== entry.id),
                    };
                }
                this.createNotificationSuccess({
                    message: this.$tc('sw-content-creator.linebreaks.fixed', total, { fixed: total }),
                });
            } catch (err) {
                this.createNotificationError({ message: err?.response?.data?.error || err.message });
            } finally {
                this.lbBusy = false;
            }
        },
    },

    beforeDestroy() {
        this.stopPolling();
    },
});
