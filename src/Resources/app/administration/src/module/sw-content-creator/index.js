import './page/sw-content-creator-generator';
import './page/sw-content-creator-batch';
import './page/sw-content-creator-tools';
import './page/sw-content-creator-settings';

const { Module } = Shopware;

Module.register('sw-content-creator', {
    type: 'plugin',
    name: 'ContentCreator',
    title: 'sw-content-creator.general.title',
    description: 'sw-content-creator.general.description',
    color: '#8b5cf6',
    icon: 'regular-pen',

    routes: {
        generator: {
            component: 'sw-content-creator-generator',
            path: 'generator',
        },
        batch: {
            component: 'sw-content-creator-batch',
            path: 'batch',
        },
        tools: {
            component: 'sw-content-creator-tools',
            path: 'tools',
        },
        settings: {
            component: 'sw-content-creator-settings',
            path: 'settings',
        },
    },

    navigation: [{
        id: 'sw-content-creator',
        label: 'sw-content-creator.general.title',
        color: '#8b5cf6',
        icon: 'regular-pen',
        path: 'sw.content.creator.generator',
        parent: 'sw-content',
        position: 100,
    }],
});
