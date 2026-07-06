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
    },

    methods: {
        formatJobDate(iso) {
            if (!iso) { return ''; }
            return new Date(iso.replace(' ', 'T')).toLocaleString();
        },
    },
});
