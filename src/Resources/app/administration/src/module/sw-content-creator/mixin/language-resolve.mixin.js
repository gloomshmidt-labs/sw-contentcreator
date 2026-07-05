const { Criteria } = Shopware.Data;

// de/en -> Locale (wie im Textoptimierung-Tool)
export const LOCALE_FOR_LANG = { de: 'de-DE', en: 'en-GB' };

/**
 * Gemeinsame Sprach-Auflösung der Generator- und Batch-Seite:
 * de/en -> languageId (via locale.code), gecacht — analog zum Tool getLanguageId().
 * Erwartet ein injiziertes `repositoryFactory` in der nutzenden Komponente.
 */
export default {
    data() {
        return {
            lang: 'de',
            languageId: null,
            languageCache: {},
            availableLangs: ['de', 'en'],
        };
    },

    created() {
        this.resolveLanguageId(this.lang);
        this.detectAvailableLanguages();
    },

    computed: {
        languageRepository() {
            return this.repositoryFactory.create('language');
        },
        languageContext() {
            return { ...Shopware.Context.api, languageId: this.languageId || Shopware.Context.api.languageId };
        },
    },

    methods: {
        // Nur Sprachen anbieten, die im Shop existieren — bei einem rein
        // deutschen Shop verschwindet die englische Option automatisch
        detectAvailableLanguages() {
            const codes = Object.entries(LOCALE_FOR_LANG);
            const criteria = new Criteria(1, 25);
            criteria.addAssociation('locale');
            criteria.addFilter(Criteria.equalsAny('locale.code', codes.map(([, locale]) => locale)));

            this.languageRepository.search(criteria, Shopware.Context.api)
                .then((result) => {
                    const found = new Set(result.map((l) => l.locale?.code));
                    const langs = codes.filter(([, locale]) => found.has(locale)).map(([lang]) => lang);
                    if (langs.length) {
                        this.availableLangs = langs;
                        if (!langs.includes(this.lang)) {
                            this.lang = langs[0];
                            this.resolveLanguageId(this.lang);
                        }
                    }
                })
                .catch(() => { /* Standard: beide anbieten */ });
        },

        resolveLanguageId(lang) {
            const locale = LOCALE_FOR_LANG[lang] || 'de-DE';
            if (this.languageCache[locale]) {
                this.languageId = this.languageCache[locale];
                return Promise.resolve(this.languageId);
            }
            const criteria = new Criteria(1, 1);
            criteria.addFilter(Criteria.equals('locale.code', locale));

            return this.languageRepository.searchIds(criteria, Shopware.Context.api)
                .then((result) => {
                    const id = result.data && result.data[0];
                    this.languageId = id || Shopware.Context.api.languageId;
                    if (id) {
                        this.languageCache[locale] = id;
                    }

                    return this.languageId;
                })
                .catch(() => { this.languageId = Shopware.Context.api.languageId; });
        },
    },
};
