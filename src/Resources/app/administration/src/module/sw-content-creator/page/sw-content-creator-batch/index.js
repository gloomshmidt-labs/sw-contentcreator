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
            dryRun: true,
            isCommitting: false,
            gapResult: null,
            gapBusy: false,
            freshnessResult: null,
            freshnessBusy: false,
            report: null,
            reportBusy: false,
            reportProgress: null,
            workerStalled: false,
            stalledPolls: 0,
            categorySalesChannelId: null,
            categoryRootId: null,
        };
    },

    computed: {
        repository() {
            return this.repositoryFactory.create(this.entityType === 'manufacturer' ? 'product_manufacturer' : this.entityType);
        },
        // Aufgelöste Sprach-ID mit Admin-Kontext als Fallback (für alle API-Calls)
        effectiveLanguageId() {
            return this.languageId || Shopware.Context.api.languageId;
        },
        // Kategorie-Auswahl auf den Baum des gewählten Verkaufskanals eingrenzen
        entityCriteria() {
            const criteria = new Shopware.Data.Criteria(1, 25);
            if (this.entityType === 'category' && this.categoryRootId) {
                criteria.addFilter(Shopware.Data.Criteria.multi('OR', [
                    Shopware.Data.Criteria.contains('path', `|${this.categoryRootId}|`),
                    Shopware.Data.Criteria.equals('id', this.categoryRootId),
                ]));
            }
            return criteria;
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
        notifyApiError(err) {
            this.createNotificationError({ message: err?.response?.data?.error || err.message });
        },

        // Gemeinsames Muster aller Scan-/Aktions-Buttons: Busy-Flag setzen,
        // Arbeit ausführen, Fehler als Notification, Flag immer zurücksetzen.
        runBusy(busyProp, work) {
            this[busyProp] = true;

            return Promise.resolve()
                .then(work)
                .catch((err) => this.notifyApiError(err))
                .finally(() => { this[busyProp] = false; });
        },

        onLangChange(value) {
            this.lang = value;
            this.selectedIds = [];
            this.resolveLanguageId(value);
        },

        onEntityTypeChange(value) {
            this.entityType = value;
            this.selectedIds = [];
            this.selectedTypes = [];
            this.categorySalesChannelId = null;
            this.categoryRootId = null;
        },

        // Verkaufskanal gewählt → dessen Navigations-Root als Kategorie-Filter laden
        onCategoryChannelChange(id) {
            this.categorySalesChannelId = id;
            this.selectedIds = [];
            if (!id) {
                this.categoryRootId = null;
                return;
            }
            this.repositoryFactory.create('sales_channel')
                .get(id, Shopware.Context.api)
                .then((salesChannel) => {
                    this.categoryRootId = salesChannel?.navigationCategoryId || null;
                })
                .catch(() => { this.categoryRootId = null; });
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
            this.runBusy('isStarting', () => this.contentCreatorApiService.startBatch({
                entityType: this.entityType,
                ids: this.selectedIds,
                types: this.selectedTypes,
                languageId: this.effectiveLanguageId,
                mode: this.mode,
                dryRun: this.dryRun,
            })
                .then((res) => {
                    this.job = { id: res.jobId, total: res.total, processed: 0, failed: 0, rejected: 0, status: 'running', dryRun: this.dryRun };
                    this.startPolling();
                }));
        },

        startPolling() {
            this.stopPolling();
            this.workerStalled = false;
            this.stalledPolls = 0;
            this.polling = setInterval(() => {
                if (!this.job) {
                    return;
                }
                this.contentCreatorApiService.batchStatus(this.job.id)
                    .then((res) => {
                        // Worker-Sackgasse erkennen: 30s ohne jeden Fortschritt
                        const done = res.job.processed + res.job.failed + (res.job.rejected || 0);
                        this.stalledPolls = done === 0 ? this.stalledPolls + 1 : 0;
                        this.workerStalled = res.job.status === 'running' && this.stalledPolls >= 15;

                        this.job = res.job;
                        if (['done', 'failed', 'done_with_errors'].includes(res.job.status)) {
                            this.stopPolling();
                            this.workerStalled = false;
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
            this.runBusy('isCommitting', () => this.contentCreatorApiService.commitBatch(this.job.id)
                .then((res) => {
                    this.createNotificationSuccess({
                        message: this.$tc('sw-content-creator.batch.committed', { applied: res.applied, errors: res.errors }, res.applied),
                    });
                    this.job = { ...this.job, committed: true };
                }));
        },

        scanGaps() {
            this.runBusy('gapBusy', () => this.contentCreatorApiService.gaps({
                entityType: this.entityType,
                languageId: this.effectiveLanguageId,
            })
                .then((res) => { this.gapResult = res.gaps; }));
        },

        gapLabel(key) {
            return this.$tc(`sw-content-creator.gaps.${key}`);
        },

        scanFreshness() {
            this.runBusy('freshnessBusy', () => this.contentCreatorApiService.freshness({
                entityType: ['product', 'category', 'manufacturer'].includes(this.entityType) ? this.entityType : 'product',
                languageId: this.effectiveLanguageId,
            })
                .then((res) => { this.freshnessResult = res; }));
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
                message: this.$tc('sw-content-creator.gaps.selected', { count: ids.length }, ids.length),
            });
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
                message: this.$tc('sw-content-creator.gaps.selected', { count: gap.ids.length }, gap.ids.length),
            });
        },

        runQualityReport() {
            this.runBusy('reportBusy', async () => {
                const items = [];
                let offset = 0;
                let scanned = 0;
                this.reportProgress = { scanned: 0 };
                for (;;) {
                    // eslint-disable-next-line no-await-in-loop
                    const page = await this.contentCreatorApiService.qualityReport({
                        entityType: this.entityType === 'category' ? 'category' : 'product',
                        languageId: this.effectiveLanguageId,
                        offset,
                    });
                    items.push(...page.items);
                    scanned += page.scanned;
                    offset += page.scanned;
                    this.reportProgress = { scanned };
                    if (page.done) {
                        break;
                    }
                }
                this.reportProgress = null;
                items.sort((a, b) => b.score - a.score);
                this.report = { items: items.slice(0, 50), total: items.length, scanned };
            });
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
                message: this.$tc('sw-content-creator.gaps.selected', { count: ids.length }, ids.length),
            });
        },

    },

    beforeDestroy() {
        this.stopPolling();
    },
});
