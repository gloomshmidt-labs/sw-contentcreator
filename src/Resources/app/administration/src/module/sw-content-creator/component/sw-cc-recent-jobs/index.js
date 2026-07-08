import template from './sw-cc-recent-jobs.html.twig';

const { Component } = Shopware;

/**
 * „Frühere Läufe"-Karte der Batch-Seite: reine Anzeige der letzten Jobs.
 * Das Wiederöffnen (Status laden, Polling, Dry-Run-Ergebnisse) bleibt auf
 * der Seite — die Komponente meldet nur den gewählten Eintrag per open-Event.
 */
Component.register('sw-cc-recent-jobs', {
    template,

    props: {
        jobs: {
            type: Array,
            required: true,
        },
        page: {
            type: Number,
            required: false,
            default: 1,
        },
        total: {
            type: Number,
            required: false,
            default: 0,
        },
    },

    computed: {
        pageCount() {
            return Math.max(1, Math.ceil(this.total / 10));
        },
    },

    methods: {
        formatJobDate(iso) {
            if (!iso) { return ''; }
            return new Date(iso.replace(' ', 'T')).toLocaleString();
        },
    },
});
