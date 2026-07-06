import template from './sw-cc-selection-list.html.twig';

const { Component } = Shopware;

/**
 * „Objekt hinzufügen"-Feld + Zählerzeile + scrollbare Namensliste der
 * Batch-Auswahl. Die Auswahl selbst (IDs + aufgelöste Namen) bleibt Seiten-
 * State — die Komponente meldet nur add/remove/clear und kapselt lediglich
 * das Zurücksetzen des Hinzufügen-Feldes nach der Auswahl.
 */
Component.register('sw-cc-selection-list', {
    template,

    props: {
        selectedIds: {
            type: Array,
            required: true,
        },
        selectionNames: {
            type: Object,
            required: true,
        },
        entityDalName: {
            type: String,
            required: true,
        },
        languageContext: {
            type: Object,
            required: true,
        },
        entityCriteria: {
            type: Object,
            required: true,
        },
    },

    data() {
        return {
            addEntityValue: null,
        };
    },

    methods: {
        addEntity(id) {
            this.$emit('add', id);
            // Feld nach dem Hinzufügen leeren (bereit für das nächste Objekt)
            this.addEntityValue = id;
            this.$nextTick(() => { this.addEntityValue = null; });
        },
    },
});
