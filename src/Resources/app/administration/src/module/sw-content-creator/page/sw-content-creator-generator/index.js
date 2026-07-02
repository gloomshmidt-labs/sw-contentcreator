import template from './sw-content-creator-generator.html.twig';
import { TextOptimiser } from '../../../content-creator/engine/engine';
import { highlightText, generateHtmlDiff, calculateFleschIndex, similarity } from '../../../content-creator/engine/analysis-view';
import { estimateCost, formatCost } from '../../../content-creator/engine/pricing';
import { titlePx, descPx, truncateTitle, truncateDesc, barColor, TITLE_LIMIT_PX, DESC_LIMIT_PX } from '../../../content-creator/engine/serp-preview';
import languageResolveMixin from '../../mixin/language-resolve.mixin';
import categoryTreeMixin from '../../mixin/category-tree.mixin';

const { Component, Mixin } = Shopware;

const FOCUS_KEYWORD_FIELD = 'content_creator_focus_keyword';

// Farben der Server-Score-Bänder (identisch zur Engine-_getRating-Palette)
const LEVEL_COLORS = {
    excellent: '#22c55e',
    good: '#84cc16',
    moderate: '#eab308',
    poor: '#f97316',
    critical: '#ef4444',
};

Component.register('sw-content-creator-generator', {
    template,

    inject: ['contentCreatorApiService', 'repositoryFactory', 'systemConfigApiService'],

    mixins: [Mixin.getByName('notification'), languageResolveMixin, categoryTreeMixin],

    data() {
        return {
            entityType: 'product',
            selectedId: null,
            entity: null,
            isLoading: false,
            generatingType: null,
            generated: {},
            mode: 'optimize',
            metaFields: { metaTitle: true, metaDescription: true, metaKeywords: true },
            viewTabs: {},
            whitelist: [],
            variantAngle: 'none',
            referenceCategoryId: null,
            referenceText: null,
            backups: {},
            focusKeyword: '',
            cannibalWarning: [],
            serverText: null,
            serverTeaser: '',
            serverMeta: null,
        };
    },

    watch: {
        focusKeyword() {
            // Kannibalisierungs-Check entprellt beim Tippen
            clearTimeout(this._cannibalTimer);
            this._cannibalTimer = setTimeout(() => this.checkCannibalization(), 800);
        },
    },

    created() {
        this.loadWhitelist();
        this.applyRouteQuery();
    },

    computed: {
        repository() {
            return this.repositoryFactory.create(this.entityDalName);
        },
        // UI-Typ → DAL-Entity (nur der Hersteller weicht ab)
        entityDalName() {
            return this.entityType === 'manufacturer' ? 'product_manufacturer' : this.entityType;
        },
        // Kategorie-Auswahl auf den Baum des gewählten Verkaufskanals eingrenzen
        // (Tool-Lösung: erst Kanal wählen, dann nur dessen navigationCategory-Unterbaum)
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
        metaFieldOptions() {
            return [
                { field: 'metaTitle', label: 'Meta-Title' },
                { field: 'metaDescription', label: 'Meta-Description' },
                { field: 'metaKeywords', label: 'Meta-Keywords' },
            ];
        },
        variantOptions() {
            return [
                { value: 'none', label: this.$tc('sw-content-creator.variants.none') },
                { value: 'default', label: this.$tc('sw-content-creator.variants.default') },
                { value: 'educational', label: this.$tc('sw-content-creator.variants.educational') },
                { value: 'therapeutic', label: this.$tc('sw-content-creator.variants.therapeutic') },
                { value: 'gift', label: this.$tc('sw-content-creator.variants.gift') },
            ];
        },
        categoryRepository() {
            return this.repositoryFactory.create('category');
        },
        typeButtons() {
            if (this.entityType === 'product') {
                return [
                    { type: 'product_description', kind: 'html' },
                    { type: 'product_meta', kind: 'meta' },
                    { type: 'faq', kind: 'html' },
                ];
            }
            if (this.entityType === 'sales_channel') {
                return [
                    { type: 'home_meta', kind: 'meta' },
                ];
            }
            if (this.entityType === 'manufacturer') {
                return [
                    { type: 'manufacturer_description', kind: 'html' },
                ];
            }

            return [
                { type: 'category_teaser', kind: 'html' },
                { type: 'category_detail', kind: 'html' },
                { type: 'category_meta', kind: 'meta' },
                { type: 'faq', kind: 'html' },
            ];
        },
        currentText() {
            // Server-Sicht bevorzugen: enthält auch Layout-Slots/Erlebniswelt
            // (Startseite, Kategorien mit Content im CMS-Layout)
            if (this.serverText !== null) {
                return this.serverText;
            }
            return this.entity ? (this.entity.description || '') : '';
        },
        currentScore() {
            return this.scoreOf(this.currentText);
        },
    },

    methods: {
        notifyApiError(err) {
            this.createNotificationError({ message: err?.response?.data?.error || err.message });
        },

        onLangChange(value) {
            this.lang = value;
            this.selectedId = null;
            this.entity = null;
            this.generated = {};
            this.serverText = null;
            this.resolveLanguageId(value).then(() => {
                if (this.categoryRootId) {
                    this.loadCategoryTree();
                }
            });
        },

        buildOptimiser() {
            return new TextOptimiser(this.lang);
        },

        loadWhitelist() {
            this.systemConfigApiService.getValues('ContentCreator.config')
                .then((values) => {
                    const raw = values['ContentCreator.config.qualityWhitelist'] || '';
                    this.whitelist = raw.split(',').map((w) => w.trim().toLowerCase()).filter((w) => w);
                })
                .catch(() => { this.whitelist = []; });
        },

        // Analyse mit Whitelist-Filter — gleiche Wertung wie das Server-Gate
        analyseText(text) {
            const opt = this.buildOptimiser();
            const r = opt.analyse(text);
            if (this.whitelist.length) {
                const keep = [];
                for (const p of r.patternsFound) {
                    if (this.whitelist.some((w) => p.pattern.toLowerCase().includes(w))) {
                        r.aiScore -= p.score;
                    } else {
                        keep.push(p);
                    }
                }
                r.patternsFound = keep;
                r.aiScore = Math.max(0, r.aiScore);
                r.rating = opt._getRating(r.aiScore);
            }

            return r;
        },

        scoreOf(text) {
            if (!text || !text.trim()) {
                return null;
            }
            try {
                const r = this.analyseText(text);
                const rating = r.rating || this.buildOptimiser()._getRating(r.aiScore);

                return { score: r.aiScore, level: rating.level, label: rating.label, color: rating.color };
            } catch {
                return null;
            }
        },

        markedHtml(text) {
            try {
                return highlightText(this.analyseText(text));
            } catch {
                return '';
            }
        },

        fleschOf(text) {
            if (!text || !text.trim()) {
                return null;
            }
            try {
                return calculateFleschIndex(text, this.lang);
            } catch {
                return null;
            }
        },

        diffHtml(type) {
            const res = this.generated[type];
            if (!res || !res.content) {
                return '';
            }
            try {
                return generateHtmlDiff(this.currentText, res.content, this.lang);
            } catch {
                return '';
            }
        },

        // Diff nur, wenn der Typ die description ersetzt (Basis clientseitig bekannt)
        canDiff(type) {
            return ['product_description', 'category_detail', 'manufacturer_description'].includes(type) && !!this.currentText.trim();
        },

        readabilityOf(type) {
            return this.generated[type]?.readability || null;
        },

        readabilityLabel(key) {
            return this.$tc(`sw-content-creator.readability.${key}`);
        },

        checkCannibalization() {
            const keyword = (this.focusKeyword || '').trim();
            if (!keyword || !['product', 'category'].includes(this.entityType)) {
                this.cannibalWarning = [];
                return;
            }
            this.contentCreatorApiService.cannibalization({
                entityType: this.entityType,
                languageId: this.languageId || Shopware.Context.api.languageId,
                keyword,
                excludeId: this.selectedId,
            })
                .then((res) => { this.cannibalWarning = res.usedBy || []; })
                .catch(() => { this.cannibalWarning = []; });
        },

        viewTab(key) {
            return this.viewTabs[key] || 'preview';
        },

        setViewTab(key, tab) {
            this.viewTabs = { ...this.viewTabs, [key]: tab };
        },

        tabStyle(key, tab) {
            const active = this.viewTab(key) === tab;

            return {
                padding: '4px 12px',
                borderRadius: '4px',
                cursor: 'pointer',
                fontSize: '12px',
                fontWeight: 600,
                border: '1px solid #d1d9e0',
                backgroundColor: active ? '#189eff' : '#fff',
                color: active ? '#fff' : '#52667a',
            };
        },

        // Gemeinsame Pill-Optik für Score- und Qualitäts-Badges
        pillStyle(backgroundColor) {
            return {
                backgroundColor,
                color: '#fff',
                padding: '2px 10px',
                borderRadius: '12px',
                fontWeight: 600,
                display: 'inline-block',
            };
        },

        badgeStyle(score) {
            return this.pillStyle(score.color);
        },

        onEntityTypeChange(value) {
            this.entityType = value;
            this.selectedId = null;
            this.entity = null;
            this.generated = {};
            this.categorySalesChannelId = null;
            this.categoryRootId = null;
            this.categoryOptions = [];
        },

        // Hook des category-tree-Mixins beim Kanalwechsel
        resetCategorySelection() {
            this.selectedId = null;
            this.entity = null;
            this.serverText = null;
            this.serverTeaser = '';
            this.serverMeta = null;
            this.generated = {};
        },

        // 1-Klick-Fix: Deep-Link aus dem Qualitäts-Report (?entityType=&id=&mode=)
        applyRouteQuery() {
            const query = this.$route?.query || {};
            if (['product', 'category', 'sales_channel', 'manufacturer'].includes(query.entityType)) {
                this.entityType = query.entityType;
            }
            if (['de', 'en'].includes(query.lang)) {
                this.lang = query.lang;
            }
            if (['create', 'optimize'].includes(query.mode)) {
                this.mode = query.mode;
            }
            if (query.id) {
                // Sprache zuerst auflösen, damit das Objekt im richtigen Kontext lädt
                this.resolveLanguageId(this.lang).then(() => this.onSelectEntity(query.id));
            }
        },

        onSelectEntity(id) {
            this.selectedId = id;
            this.generated = {};
            this.backups = {};
            this.focusKeyword = '';
            this.serverText = null;
            if (!id) {
                this.entity = null;
                return;
            }
            this.isLoading = true;
            this.repository.get(id, this.languageContext)
                .then((e) => {
                    this.entity = e;
                    const customFields = e?.translated?.customFields || e?.customFields || {};
                    this.focusKeyword = customFields[FOCUS_KEYWORD_FIELD] || '';
                    this.typeButtons.forEach((btn) => this.loadBackupInfo(btn.type));
                })
                .catch((err) => this.notifyApiError(err))
                .finally(() => { this.isLoading = false; });

            // Bestandstext aus Server-Sicht (inkl. Layout-Slots/Erlebniswelt)
            this.contentCreatorApiService.currentText({
                entityType: this.entityType,
                id,
                languageId: this.languageId || Shopware.Context.api.languageId,
            })
                .then((res) => {
                    this.serverText = res.text || '';
                    this.serverTeaser = res.teaser || '';
                    this.serverMeta = {
                        metaTitle: res.metaTitle || '',
                        metaDescription: res.metaDescription || '',
                        keywords: res.keywords || '',
                    };
                })
                .catch(() => {
                    this.serverText = null;
                    this.serverTeaser = '';
                    this.serverMeta = null;
                });
        },

        // Fokus-Keyword an der Entity persistieren (translatable customField)
        persistFocusKeyword() {
            if (!this.entity || !['product', 'category', 'manufacturer'].includes(this.entityType)) {
                return Promise.resolve();
            }
            const current = (this.entity.translated?.customFields || this.entity.customFields || {})[FOCUS_KEYWORD_FIELD] || '';
            if ((this.focusKeyword || '') === current) {
                return Promise.resolve();
            }
            this.entity.customFields = { ...(this.entity.customFields || {}), [FOCUS_KEYWORD_FIELD]: this.focusKeyword || null };

            return this.repository.save(this.entity, this.languageContext).catch(() => {});
        },

        typeLabel(type) {
            return this.$tc(`sw-content-creator.types.${type}`);
        },

        onModeChange(value) {
            this.mode = value;
        },

        setMetaField(field, checked) {
            this.metaFields = { ...this.metaFields, [field]: checked };
        },

        isMetaType(type) {
            return type.endsWith('_meta');
        },

        // Referenztext (andere Kanal-Variante) laden — als Anti-Duplicate-Kontext
        loadReferenceText() {
            if (this.entityType !== 'category' || !this.referenceCategoryId) {
                return Promise.resolve(null);
            }

            return this.categoryRepository.get(this.referenceCategoryId, this.languageContext)
                .then((ref) => {
                    const div = document.createElement('div');
                    div.innerHTML = ref?.description || '';
                    this.referenceText = div.textContent.trim().substring(0, 2000) || null;

                    return this.referenceText;
                })
                .catch(() => null);
        },

        // Duplicate-Content-Check gegen die Referenz-Variante (Wort-3-Gramm-Jaccard)
        similarityOf(type) {
            const res = this.generated[type];
            if (!res?.content || !this.referenceText) {
                return null;
            }

            return similarity(res.content, this.referenceText);
        },

        similarityColor(value) {
            return value < 20 ? '#22c55e' : (value < 40 ? '#eab308' : '#ef4444');
        },

        generate(type) {
            if (!this.selectedId) {
                return;
            }
            // Optimieren ohne Bestandstext ist für Textfelder nicht möglich
            if (this.mode === 'optimize' && !this.isMetaType(type) && !this.currentText.trim()) {
                this.createNotificationWarning({ message: this.$tc('sw-content-creator.generator.noExistingText') });
                return;
            }
            const payload = {
                type,
                entityType: this.entityType,
                id: this.selectedId,
                languageId: this.languageId,
                lang: this.lang,
                mode: this.mode,
            };
            if (this.mode === 'optimize' && this.isMetaType(type)) {
                const fields = Object.keys(this.metaFields).filter((k) => this.metaFields[k]);
                if (!fields.length) {
                    this.createNotificationWarning({ message: this.$tc('sw-content-creator.generator.noMetaFields') });
                    return;
                }
                payload.metaFields = fields;
            }
            this.generatingType = type;
            const contextPromise = this.entityType === 'category'
                ? this.loadReferenceText().then((avoidSimilarTo) => {
                    const ctx = {};
                    if (this.variantAngle !== 'none') {
                        ctx.variantAngle = this.variantAngle;
                    }
                    if (avoidSimilarTo) {
                        ctx.avoidSimilarTo = avoidSimilarTo;
                    }

                    return ctx;
                })
                : Promise.resolve({});

            contextPromise
                .then((ctx) => {
                    if (this.focusKeyword.trim()) {
                        ctx.focusKeyword = this.focusKeyword.trim();
                    }
                    if (Object.keys(ctx).length) {
                        payload.context = ctx;
                    }

                    return this.persistFocusKeyword().then(() => this.contentCreatorApiService.generate(payload));
                })
                .then((res) => {
                    this.generated = { ...this.generated, [type]: res.result };
                })
                .catch((err) => this.notifyApiError(err))
                .finally(() => { this.generatingType = null; });
        },

        generatedScore(type) {
            const res = this.generated[type];
            if (!res || !res.content) {
                return null;
            }

            return this.scoreOf(res.content);
        },

        qualityOf(type) {
            return this.generated[type]?.quality || null;
        },

        qualityBadgeStyle(quality) {
            return this.pillStyle(LEVEL_COLORS[quality.level] || '#758ca3');
        },

        qualityLevelLabel(level) {
            return this.$tc(`sw-content-creator.levels.${level}`);
        },

        focusChecksOf(type) {
            return this.generated[type]?.focusChecks || null;
        },

        focusCheckLabel(key) {
            return this.$tc(`sw-content-creator.focus.${key}`);
        },

        serpPreview(type) {
            const meta = this.generated[type]?.meta;
            if (!meta) {
                return null;
            }
            const tPx = titlePx(meta.metaTitle);
            const dPx = descPx(meta.metaDescription);

            return {
                title: truncateTitle(meta.metaTitle),
                desc: truncateDesc(meta.metaDescription),
                titlePx: tPx,
                descPx: dPx,
                titleLimit: TITLE_LIMIT_PX,
                descLimit: DESC_LIMIT_PX,
                titleColor: barColor(tPx, TITLE_LIMIT_PX),
                descColor: barColor(dPx, DESC_LIMIT_PX),
                titlePercent: Math.min(100, Math.round((tPx / TITLE_LIMIT_PX) * 100)),
                descPercent: Math.min(100, Math.round((dPx / DESC_LIMIT_PX) * 100)),
            };
        },

        costOf(type) {
            const res = this.generated[type];
            if (!res?.usage) {
                return '';
            }
            const cost = estimateCost(res.model, res.usage.input, res.usage.output);

            return cost === null ? '' : formatCost(cost);
        },

        canApply() {
            return true;
        },

        reloadEntity() {
            if (!this.selectedId) {
                return Promise.resolve();
            }

            return this.repository.get(this.selectedId, this.languageContext)
                .then((e) => { this.entity = e; });
        },

        // Übernehmen läuft für ALLE Typen über das Backend (ContentWriter):
        // so ist das automatische Backup vor jedem Überschreiben garantiert.
        apply(type) {
            const res = this.generated[type];
            if (!res || !this.selectedId) {
                return;
            }
            this.isLoading = true;
            this.contentCreatorApiService.apply({
                entityType: this.entityType,
                id: this.selectedId,
                type,
                languageId: this.languageId,
                result: { content: res.content, meta: res.meta },
            })
                .then(() => {
                    this.createNotificationSuccess({ message: this.$tc('sw-content-creator.generator.saved') });
                    this.loadBackupInfo(type);
                    // Nur Bestandstext + Score aktualisieren – bereits generierte Ergebnisse bleiben erhalten.
                    return this.reloadEntity();
                })
                .catch((err) => this.notifyApiError(err))
                .finally(() => { this.isLoading = false; });
        },

        loadBackupInfo(type) {
            if (!this.selectedId) {
                return;
            }
            this.contentCreatorApiService.latestBackup({
                entityType: this.entityType,
                id: this.selectedId,
                type,
                languageId: this.languageId,
            })
                .then((res) => {
                    this.backups = { ...this.backups, [type]: res.backup || null };
                })
                .catch(() => {});
        },

        restoreBackup(type) {
            const backup = this.backups[type];
            if (!backup) {
                return;
            }
            this.isLoading = true;
            this.contentCreatorApiService.restoreBackup(backup.id)
                .then(() => {
                    this.createNotificationSuccess({ message: this.$tc('sw-content-creator.generator.restored') });
                    this.loadBackupInfo(type);
                    return this.reloadEntity();
                })
                .catch((err) => this.notifyApiError(err))
                .finally(() => { this.isLoading = false; });
        },

        copyResult(btn) {
            const res = this.generated[btn.type];
            if (!res) {
                return;
            }
            const text = btn.kind === 'meta'
                ? `${res.meta.metaTitle}\n${res.meta.metaDescription}\n${res.meta.metaKeywords}`
                : res.content;
            navigator.clipboard.writeText(text).then(() => {
                this.createNotificationSuccess({ message: this.$tc('sw-content-creator.generator.copied') });
            });
        },
    },
});
