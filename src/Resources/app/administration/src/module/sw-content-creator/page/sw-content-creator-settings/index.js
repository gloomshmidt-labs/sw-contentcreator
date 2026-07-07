import template from './sw-content-creator-settings.html.twig';
import { estimateCost, formatCost } from '../../../content-creator/engine/pricing';

const { Component, Mixin } = Shopware;

const DOMAIN = 'ContentCreator.config';

Component.register('sw-content-creator-settings', {
    template,

    inject: ['systemConfigApiService', 'contentCreatorApiService'],

    mixins: [Mixin.getByName('notification')],

    data() {
        return {
            config: {},
            isLoading: false,
            isSaving: false,
            isTesting: false,
            usageRows: [],
        };
    },

    computed: {
        startupChecklist() {
            const provider = this.config['ContentCreator.config.provider'] || 'claude';
            const key = provider === 'openai'
                ? this.config['ContentCreator.config.openaiApiKey']
                : this.config['ContentCreator.config.anthropicApiKey'];

            return [
                { ok: !!(key && String(key).length > 20), label: this.$tc('sw-content-creator.settings.checkKey'), hint: this.$tc('sw-content-creator.settings.checkKeyHint') },
                { ok: true, label: this.$tc('sw-content-creator.settings.checkWorker'), hint: '' },
                { ok: !!this.config['ContentCreator.config.batchModel'] || provider !== 'claude', label: this.$tc('sw-content-creator.settings.checkBatchModel'), hint: '' },
                { ok: !!this.config['ContentCreator.config.redirectFile'], label: this.$tc('sw-content-creator.settings.checkRedirect'), hint: this.$tc('sw-content-creator.settings.checkRedirectHint') },
            ];
        },

        providerOptions() {
            return [
                { value: 'claude', label: 'Anthropic Claude' },
                { value: 'openai', label: 'OpenAI' },
            ];
        },
        anthropicModelOptions() {
            return [
                { value: 'claude-opus-4-8', label: this.$tc('sw-content-creator.settings.modelOpus') },
                { value: 'claude-sonnet-4-6', label: this.$tc('sw-content-creator.settings.modelSonnet') },
                { value: 'claude-haiku-4-5', label: this.$tc('sw-content-creator.settings.modelHaiku') },
            ];
        },
        batchModelOptions() {
            return [
                { value: 'claude-sonnet-4-6', label: 'Claude Sonnet 4.6' },
                { value: 'claude-haiku-4-5', label: 'Claude Haiku 4.5' },
                { value: 'claude-opus-4-8', label: 'Claude Opus 4.8' },
            ];
        },
    },

    created() {
        this.loadUsage();
        this.load();
    },

    methods: {
        loadUsage() {
            this.contentCreatorApiService.usage()
                .then((res) => { this.usageRows = res.rows || []; })
                .catch(() => { this.usageRows = []; });
        },

        usageCost(row) {
            const cost = estimateCost(row.model, Number(row.inputTokens) || 0, Number(row.outputTokens) || 0);
            return cost === null ? '—' : formatCost(cost);
        },


        key(name) {
            return `${DOMAIN}.${name}`;
        },

        get(name) {
            return this.config[this.key(name)];
        },

        set(name, value) {
            this.config[this.key(name)] = value;
        },

        defaults() {
            return {
                [this.key('provider')]: 'claude',
                [this.key('anthropicModel')]: 'claude-opus-4-8',
                [this.key('batchModel')]: 'claude-sonnet-4-6',
                [this.key('openaiModel')]: 'gpt-4o',
                [this.key('includeAnimalProfile')]: true,
                [this.key('includeFunFact')]: true,
                [this.key('dailyFillEnabled')]: false,
                [this.key('dailyFillLimit')]: 25,
                [this.key('qualityMaxScore')]: 30,
                [this.key('qualityMaxRetries')]: 2,
                [this.key('qualityWhitelist')]: '',
                [this.key('researchEnabled')]: false,
            };
        },

        load() {
            this.isLoading = true;
            this.systemConfigApiService.getValues(DOMAIN)
                .then((values) => {
                    this.config = { ...this.defaults(), ...values };
                })
                .catch((err) => { this.createNotificationError({ message: err.message }); })
                .finally(() => { this.isLoading = false; });
        },

        save() {
            this.isSaving = true;
            // Branchen-Profil: leerer String bedeutet "eingebauter Standard" —
            // als null speichern, sonst würde der Standard dauerhaft überschrieben
            ['industryKeywordsDe', 'industryKeywordsEn', 'industryQaDe', 'industryQaEn'].forEach((k) => {
                const key = `ContentCreator.config.${k}`;
                if (typeof this.config[key] === 'string' && this.config[key].trim() === '') {
                    this.config[key] = null;
                }
            });
            this.systemConfigApiService.saveValues(this.config)
                .then(() => {
                    this.createNotificationSuccess({ message: this.$tc('sw-content-creator.settings.saved') });
                })
                .catch((err) => { this.createNotificationError({ message: err.message }); })
                .finally(() => { this.isSaving = false; });
        },

        testConnection() {
            this.isTesting = true;
            this.contentCreatorApiService.testConnection(this.get('provider'))
                .then((res) => {
                    this.createNotificationSuccess({
                        message: this.$tc('sw-content-creator.settings.testSuccess', {
                            provider: res.provider,
                            model: res.model,
                        }, 0),
                    });
                })
                .catch((err) => {
                    this.createNotificationError({ message: err?.response?.data?.error || err.message });
                })
                .finally(() => { this.isTesting = false; });
        },
    },
});
