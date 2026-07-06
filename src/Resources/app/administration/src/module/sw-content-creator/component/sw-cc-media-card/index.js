import template from './sw-cc-media-card.html.twig';
import busyMixin from '../../mixin/busy.mixin';

const { Component, Mixin } = Shopware;

/**
 * „Produktbilder & Alt-Texte"-Karte des Generators: lädt die Bilder des
 * gewählten Produkts selbst und kapselt alle Aktionen (Alt-Inline-Edit inkl.
 * EN-Nachziehen, Alt-Generierung/-Übernahme, SEO-Dateinamen-Vorschläge).
 * Sprach-State (lang/languageId/languageContext) bleibt auf der Seite:
 * resolveLanguageId kommt als Funktions-Prop, damit das EN-Nachziehen die
 * SEITEN-languageId exakt wie zuvor umschaltet und wieder zurücksetzt.
 */
Component.register('sw-cc-media-card', {
    template,

    inject: ['contentCreatorApiService', 'repositoryFactory'],

    mixins: [Mixin.getByName('notification'), busyMixin],

    props: {
        entityType: {
            type: String,
            required: true,
        },
        selectedId: {
            type: String,
            required: false,
            default: null,
        },
        lang: {
            type: String,
            required: true,
        },
        languageId: {
            type: String,
            required: false,
            default: null,
        },
        languageContext: {
            type: Object,
            required: true,
        },
        mode: {
            type: String,
            required: true,
        },
        availableLangs: {
            type: Array,
            required: true,
        },
        // Funktions-Prop statt eigener Sprach-Auflösung: das EN-Nachziehen
        // muss die languageId der Seite mutieren (und zurücksetzen), sonst
        // liefen Seite und Karte mit divergierenden Sprach-IDs
        resolveLanguageId: {
            type: Function,
            required: true,
        },
    },

    data() {
        return {
            productMedia: [],
            renameSuggestionsLoaded: false,
        };
    },

    watch: {
        // Laden bei (Neu-)Auswahl; beim Abwählen bleibt die Liste wie bisher
        // unangetastet (die Karte verschwindet über den length-Guard im Template)
        selectedId: {
            handler(id) {
                if (id) {
                    this.loadProductMedia();
                }
            },
            immediate: true,
        },
        // Sprachwechsel: Reset wie früher in onLangChange der Seite — die Seite
        // wählt das Objekt danach neu aus, was den Reload hier auslöst
        lang() {
            this.productMedia = [];
            this.renameSuggestionsLoaded = false;
        },
    },

    methods: {
        // Produktbilder für die Alt-Text-Karte (nur bei Produkten)
        loadProductMedia() {
            this.productMedia = [];
            if (this.entityType !== 'product' || !this.selectedId) {
                return Promise.resolve();
            }
            const criteria = new Shopware.Data.Criteria(1, 50);
            criteria.addFilter(Shopware.Data.Criteria.equals('productId', this.selectedId));
            criteria.addAssociation('media');
            criteria.addSorting(Shopware.Data.Criteria.sort('position', 'ASC'));
            return this.repositoryFactory.create('product_media')
                .search(criteria, this.languageContext)
                .then((result) => {
                    this.productMedia = result.map((pm) => ({
                        mediaId: pm.mediaId,
                        url: pm.media?.url || '',
                        fileName: pm.media?.fileName || '',
                        alt: pm.media?.translated?.alt || pm.media?.alt || '',
                        altEdit: pm.media?.translated?.alt || pm.media?.alt || '',
                        generating: false,
                        result: null,
                        score: null,
                        suggestedName: null,
                    }));
                    this.renameSuggestionsLoaded = false;
                })
                .catch(() => { this.productMedia = []; });
        },

        // Dateinamen-Vorschläge gezielt für DIESES Produkt laden
        loadRenameSuggestions() {
            this.contentCreatorApiService.mediaRenameScan({
                languageId: this.languageId || Shopware.Context.api.languageId,
                productId: this.selectedId,
            })
                .then((res) => {
                    const byId = new Map((res.items || []).map((i) => [i.mediaId, i.suggestedName]));
                    // Immer editierbar: ohne Verbesserungsvorschlag wird der
                    // aktuelle Name vorbefüllt (freies Umbenennen möglich)
                    this.productMedia.forEach((item) => {
                        item.suggestedName = byId.get(item.mediaId) || item.fileName;
                    });
                    this.renameSuggestionsLoaded = true;
                    if (!byId.size) {
                        this.createNotificationInfo({ message: this.$tc('sw-content-creator.generator.noRenameCandidates') });
                    }
                })
                .catch((err) => this.notifyApiError(err));
        },

        renameImage(item) {
            if (!item.suggestedName) {
                return;
            }
            // Unveränderte Namen nicht sinnlos umbenennen/protokollieren
            if (item.suggestedName === item.fileName) {
                this.createNotificationInfo({ message: this.$tc('sw-content-creator.generator.nameUnchanged') });
                return;
            }
            item.generating = true;
            this.contentCreatorApiService.mediaRenameApply([{
                mediaId: item.mediaId,
                newName: item.suggestedName,
                currentName: item.fileName,
            }])
                .then((res) => {
                    this.createNotificationSuccess({
                        message: this.$tc('sw-content-creator.rename.done', { renamed: res.renamed, errors: (res.errors || []).length }, res.renamed),
                    });
                    // URL + Dateiname haben sich geändert → Bilderliste neu laden,
                    // danach die Vorschläge der ÜBRIGEN Bilder automatisch nachladen
                    return this.loadProductMedia().then(() => this.loadRenameSuggestions());
                })
                .catch((err) => this.notifyApiError(err))
                .finally(() => { item.generating = false; });
        },

        generateAlt(item) {
            item.generating = true;
            this.contentCreatorApiService.generate({
                entityType: 'media',
                id: item.mediaId,
                type: 'media_alt',
                languageId: this.languageId,
                mode: this.mode,
            })
                .then((res) => {
                    item.result = res.result?.content || '';
                    item.score = res.result?.quality?.score ?? null;
                })
                .catch((err) => this.notifyApiError(err))
                .finally(() => { item.generating = false; });
        },

        // Bestands-Alt direkt korrigieren (schreibt Alt+Title mit Backup);
        // optional den englischen Alt gleich aus dem korrigierten Deutsch nachziehen
        saveAltEdit(item, updateEnglish = false) {
            const text = (item.altEdit || '').trim();
            if (!text || text === item.alt) {
                return;
            }
            item.generating = true;
            this.contentCreatorApiService.apply({
                entityType: 'media',
                id: item.mediaId,
                type: 'media_alt',
                languageId: this.languageId,
                result: { content: text },
            })
                .then(() => {
                    item.alt = text;
                    if (!updateEnglish) {
                        this.createNotificationSuccess({ message: this.$tc('sw-content-creator.generator.saved') });
                        return null;
                    }

                    // Englisch nachziehen: Übersetzungs-Modus liest den soeben
                    // gespeicherten deutschen Alt
                    return this.resolveLanguageId('en').then((enId) => this.contentCreatorApiService.generate({
                        entityType: 'media',
                        id: item.mediaId,
                        type: 'media_alt',
                        languageId: enId,
                        mode: 'create',
                    }).then((res) => this.contentCreatorApiService.apply({
                        entityType: 'media',
                        id: item.mediaId,
                        type: 'media_alt',
                        languageId: enId,
                        result: { content: res.result?.content || '' },
                    })).then(() => {
                        this.createNotificationSuccess({ message: this.$tc('sw-content-creator.generator.savedWithEn') });
                    }));
                })
                .catch((err) => this.notifyApiError(err))
                .finally(() => {
                    item.generating = false;
                    // languageId wieder auf die aktuelle Auswahl zurücksetzen
                    this.resolveLanguageId(this.lang);
                });
        },

        applyAlt(item) {
            if (!item.result) {
                return;
            }
            this.contentCreatorApiService.apply({
                entityType: 'media',
                id: item.mediaId,
                type: 'media_alt',
                languageId: this.languageId,
                result: { content: item.result },
            })
                .then(() => {
                    this.createNotificationSuccess({ message: this.$tc('sw-content-creator.generator.saved') });
                    item.alt = item.result;
                    item.result = null;
                })
                .catch((err) => this.notifyApiError(err));
        },
    },
});
