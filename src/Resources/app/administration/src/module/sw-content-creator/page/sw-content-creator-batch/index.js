import template from './sw-content-creator-batch.html.twig';
import { estimateCost, formatCost } from '../../../content-creator/engine/pricing';
import languageResolveMixin from '../../mixin/language-resolve.mixin';
import categoryTreeMixin from '../../mixin/category-tree.mixin';

const { Component, Mixin } = Shopware;

Component.register('sw-content-creator-batch', {
    template,

    inject: ['contentCreatorApiService', 'repositoryFactory'],

    mixins: [Mixin.getByName('notification'), languageResolveMixin, categoryTreeMixin],

    data() {
        return {
            entityType: 'product',
            selectedIds: [],
            selectedTypes: [],
            mode: 'optimize',
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
            dryRunResults: null,
            dryRunResultsBusy: false,
            workerStalled: false,
            stalledPolls: 0,
            manufacturerFilterId: null,
            selectionNames: {},
            recentJobs: [],
            jobsPage: 1,
            jobsTotal: 0,
        };
    },

    created() {
        this.loadRecentJobs();
    },

    watch: {
        selectedIds() {
            clearTimeout(this._namesTimer);
            this._namesTimer = setTimeout(() => this.resolveSelectionNames(), 200);
        },
    },

    computed: {
        repository() {
            return this.repositoryFactory.create(this.entityDalName);
        },
        // UI-Typ → DAL-Entity (nur der Hersteller weicht ab)
        entityDalName() {
            return this.entityType === 'manufacturer' ? 'product_manufacturer' : this.entityType;
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
            const labels = { de: 'Deutsch', en: 'English' };

            return this.availableLangs.map((value) => ({ value, label: labels[value] }));
        },
        modeOptions() {
            return [
                { value: 'create', label: this.$tc('sw-content-creator.generator.modeCreate') },
                { value: 'optimize', label: this.$tc('sw-content-creator.generator.modeOptimize') },
            ];
        },
        typeOptions() {
            if (this.entityType === 'product') {
                return ['product_description', 'product_meta', 'faq', 'media_alt', 'product_feed'];
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
            this.categoryOptions = [];
            // Scan-Ergebnisse gehören zum alten Objekt-Typ — ihre "Übernehmen"-
            // Buttons würden sonst fremde IDs in die neue Auswahl spülen
            this.gapResult = null;
            this.report = null;
            this.freshnessResult = null;
        },

        // Hook des category-tree-Mixins beim Kanalwechsel
        resetCategorySelection() {
            this.selectedIds = [];
        },

        loadRecentJobs(page = null) {
            if (page !== null) {
                this.jobsPage = page;
            }
            this.contentCreatorApiService.batchJobs(this.jobsPage)
                .then((res) => {
                    this.recentJobs = res.jobs || [];
                    this.jobsTotal = res.total || 0;
                    // Leere Seite nach Löschungen: eine Seite zurückblättern
                    if (!this.recentJobs.length && this.jobsPage > 1) {
                        this.loadRecentJobs(this.jobsPage - 1);
                    }
                })
                .catch(() => { this.recentJobs = []; this.jobsTotal = 0; });
        },

        // Zurück zur „Frühere Läufe"-Übersicht — auch bei bereits
        // übernommenen oder fehlgeschlagenen Jobs (User-Wunsch)
        closeJob() {
            this.stopPolling();
            this.job = null;
            this.dryRunResults = null;
            this.loadRecentJobs();
        },

        // Früheren Lauf wiederöffnen: Status laden, bei laufenden Jobs weiter
        // pollen, bei Dry-Runs die offenen Ergebnisse anzeigen
        openJob(jobEntry) {
            this.job = {
                id: jobEntry.id,
                total: jobEntry.total,
                processed: jobEntry.processed,
                failed: jobEntry.failed,
                rejected: jobEntry.rejected,
                status: jobEntry.status,
                dryRun: jobEntry.dryRun,
                // Wiedereröffnete Jobs: die Review-Buttons hängen an pendingResults
                // (Status-Endpoint-Name) — die Job-Liste liefert es als openResults
                pendingResults: jobEntry.openResults,
            };
            this.dryRunResults = [];
            if (['open', 'running'].includes(jobEntry.status)) {
                this.pollStatus();
            } else if (jobEntry.dryRun && jobEntry.openResults > 0) {
                this.loadDryRunResults();
            }
        },

        onManufacturerFilterChange(id) {
            this.manufacturerFilterId = id || null;
        },

        onIdsChange(ids) {
            this.selectedIds = ids || [];
        },

        // Auswahl-Anzeige: Namen zu den IDs auflösen (Medien: Dateiname)
        resolveSelectionNames() {
            const ids = this.selectedIds.slice(0, 500);
            if (!ids.length) {
                this.selectionNames = {};
                return;
            }
            const criteria = new Shopware.Data.Criteria(1, 500);
            criteria.setIds(ids);
            this.repository.search(criteria, this.languageContext)
                .then((result) => {
                    const names = {};
                    result.forEach((e) => {
                        names[e.id] = e.translated?.name || e.name || e.fileName || e.translated?.alt || e.id;
                    });
                    this.selectionNames = names;
                })
                .catch(() => { this.selectionNames = {}; });
        },

        // add-Event der Auswahl-Liste (das Leeren des Feldes kapselt die Komponente)
        addEntity(id) {
            if (id && !this.selectedIds.includes(id)) {
                this.selectedIds = [...this.selectedIds, id];
            }
        },

        removeEntity(id) {
            this.selectedIds = this.selectedIds.filter((x) => x !== id);
        },

        clearSelection() {
            this.selectedIds = [];
        },

        typeLabel(type) {
            const key = `sw-content-creator.types.${type}`;
            const label = this.$tc(key);
            // Unbekannter Typ (z.B. Fehler vor der Typ-Schleife): roh anzeigen statt Snippet-Key
            return label === key ? type : label;
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
                    this.dryRunResults = null;
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

        loadDryRunResults() {
            if (!this.job?.id) {
                return;
            }
            this.runBusy('dryRunResultsBusy', () => this.contentCreatorApiService.batchResults(this.job.id)
                .then((res) => { this.dryRunResults = res.results || []; }));
        },

        // Edit direkt am gespeicherten Ergebnis — der Commit übernimmt den editierten Stand
        saveDryRunEdit(result) {
            this.contentCreatorApiService.updateBatchResult(result.id, {
                content: typeof result.content === 'string' ? result.content : undefined,
                meta: result.meta || undefined,
                feed: result.feed || undefined,
            }).catch((err) => this.notifyApiError(err));
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
                    this.closeJob();
                }));
        },

        scanGaps() {
            this.runBusy('gapBusy', () => this.contentCreatorApiService.gaps({
                entityType: this.entityType,
                languageId: this.effectiveLanguageId,
                manufacturerId: this.manufacturerFilterId || undefined,
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

    // Vue 3: beforeDestroy existiert nicht mehr (wird still ignoriert) — nur
    // beforeUnmount stoppt das Polling beim Verlassen der Seite wirklich
    beforeUnmount() {
        this.stopPolling();
    },
});
