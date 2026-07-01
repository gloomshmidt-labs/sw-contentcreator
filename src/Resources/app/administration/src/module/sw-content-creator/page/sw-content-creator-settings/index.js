import template from './sw-content-creator-settings.html.twig';

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
        };
    },

    computed: {
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
        this.load();
    },

    methods: {
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
                        message: this.$tc('sw-content-creator.settings.testSuccess', 0, {
                            provider: res.provider,
                            model: res.model,
                        }),
                    });
                })
                .catch((err) => {
                    this.createNotificationError({ message: err?.response?.data?.error || err.message });
                })
                .finally(() => { this.isTesting = false; });
        },
    },
});
