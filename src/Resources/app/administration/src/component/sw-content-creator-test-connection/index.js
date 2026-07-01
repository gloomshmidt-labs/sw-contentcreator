import template from './sw-content-creator-test-connection.html.twig';

const { Component, Mixin } = Shopware;

Component.register('content-creator-test-connection', {
    template,

    inject: ['contentCreatorApiService'],

    mixins: [Mixin.getByName('notification')],

    props: {
        label: {
            type: String,
            required: false,
            default: '',
        },
    },

    data() {
        return {
            isLoading: false,
        };
    },

    methods: {
        onTest() {
            this.isLoading = true;
            this.contentCreatorApiService.testConnection()
                .then((res) => {
                    this.createNotificationSuccess({
                        message: this.$tc('sw-content-creator.settings.testSuccess', 0, {
                            provider: res.provider,
                            model: res.model,
                        }),
                    });
                })
                .catch((err) => {
                    const msg = err?.response?.data?.error || err.message;
                    this.createNotificationError({ message: msg });
                })
                .finally(() => {
                    this.isLoading = false;
                });
        },
    },
});
